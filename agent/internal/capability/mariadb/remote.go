package mariadb

import (
	"bytes"
	"context"
	"fmt"
	"net/netip"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"

	"github.com/mikho/LESta/agent/internal/atomicfile"
)

// Remote access: an extra user with remote hosts gets one more MariaDB
// account per host, '<name>'@'<address>', beside its local '<name>'@'127.0.0.1'
// one. Each is a copy of the local account: the same password (its stored
// hash, read from the server, so the panel never has to keep the password),
// the same grant, REQUIRE SSL. The remote accounts are derived again from
// the local one after every change, so a rotation, an access change,
// suspend and unsuspend all reach them without any bookkeeping of their own.
// The firewall's allow sets are derived from the server's accounts in turn,
// and the node hostname's certificate is copied in for the instance to
// present.

// nativePasswordHashPattern is a mysql_native_password hash as MariaDB
// stores it, the only form a remote account copies. It is interpolated
// into SQL, so nothing else is accepted.
var nativePasswordHashPattern = regexp.MustCompile(`^\*[0-9A-F]{40}$`)

// remoteAccountDDL (re)creates one remote account for u at host, copying
// the local account's password hash, and grants u's access unless the
// database is suspended. CREATE OR REPLACE is what makes a re-derivation
// pick up a rotated password or a changed access; the account has no state
// of its own to lose.
func remoteAccountDDL(databaseName string, u User, host, hash string, suspended bool) string {
	script := fmt.Sprintf("CREATE OR REPLACE USER '%s'@'%s' IDENTIFIED BY PASSWORD '%s' REQUIRE SSL;\n", u.Name, host, hash)
	if !suspended {
		script += fmt.Sprintf("GRANT %s ON `%s`.* TO '%s'@'%s';\n", extraUserPrivileges[u.Access], databaseName, u.Name, host)
	}

	return script
}

// sqlNameList is names as a quoted SQL list. Every name has passed
// extraUserSuffixPattern, so it needs no escaping.
func sqlNameList(names []string) string {
	quoted := make([]string, len(names))
	for i, name := range names {
		quoted[i] = "'" + name + "'"
	}

	return strings.Join(quoted, ", ")
}

// liveRemoteHosts is every host other than the local one that the named
// users have an account at, by user name.
func liveRemoteHosts(ctx context.Context, cfg Config, names []string) (map[string][]string, error) {
	out, err := runSQL(ctx, cfg, fmt.Sprintf(
		"SELECT User, Host FROM mysql.user WHERE User IN (%s) AND Host <> '%s' ORDER BY User, Host;\n",
		sqlNameList(names), grantHost,
	))
	if err != nil {
		return nil, err
	}

	hosts := map[string][]string{}
	for _, line := range strings.Split(out, "\n") {
		name, host, ok := strings.Cut(strings.TrimSpace(line), "\t")
		if ok {
			hosts[name] = append(hosts[name], host)
		}
	}

	return hosts, nil
}

// localPasswordHashes is the stored password hash of each named user's
// local account.
func localPasswordHashes(ctx context.Context, cfg Config, names []string) (map[string]string, error) {
	out, err := runSQL(ctx, cfg, fmt.Sprintf(
		"SELECT User, authentication_string FROM mysql.user WHERE User IN (%s) AND Host = '%s';\n",
		sqlNameList(names), grantHost,
	))
	if err != nil {
		return nil, err
	}

	hashes := map[string]string{}
	for _, line := range strings.Split(out, "\n") {
		name, hash, ok := strings.Cut(strings.TrimSpace(line), "\t")
		if !ok {
			continue
		}
		if !nativePasswordHashPattern.MatchString(hash) {
			return nil, fmt.Errorf("the local account of %s does not use a mysql_native_password hash, so remote accounts cannot copy it", name)
		}
		hashes[name] = hash
	}

	return hashes, nil
}

// syncRemoteAccounts derives every remote account of payload's users from
// their local accounts: a host no longer listed is dropped, every listed
// host is (re)created. gone are users that no longer exist (removed by this
// update, or every user of a deleted database); all their remote accounts
// are dropped. Called after the operation's own DDL, so the local accounts
// it copies are already current. Reports whether any remote account was
// created or dropped.
func syncRemoteAccounts(ctx context.Context, cfg Config, payload Payload, gone []User) (bool, error) {
	names := make([]string, 0, len(payload.Users)+len(gone))
	var remoteNames []string
	for _, u := range payload.Users {
		names = append(names, u.Name)
		if len(u.RemoteHosts) > 0 {
			remoteNames = append(remoteNames, u.Name)
		}
	}
	for _, u := range gone {
		names = append(names, u.Name)
	}
	if len(names) == 0 {
		return false, nil
	}

	live, err := liveRemoteHosts(ctx, cfg, names)
	if err != nil {
		return false, fmt.Errorf("reading remote accounts: %w", err)
	}

	hashes := map[string]string{}
	if len(remoteNames) > 0 {
		if hashes, err = localPasswordHashes(ctx, cfg, remoteNames); err != nil {
			return false, fmt.Errorf("reading password hashes: %w", err)
		}
	}

	var b strings.Builder
	for _, u := range payload.Users {
		wanted := make(map[string]bool, len(u.RemoteHosts))
		for _, host := range u.RemoteHosts {
			wanted[host] = true
		}
		for _, host := range live[u.Name] {
			if !wanted[host] {
				fmt.Fprintf(&b, "DROP USER IF EXISTS '%s'@'%s';\n", u.Name, host)
			}
		}
		if len(u.RemoteHosts) == 0 {
			continue
		}

		hash, ok := hashes[u.Name]
		if !ok {
			return false, fmt.Errorf("%s has remote hosts but no local account to copy", u.Name)
		}
		for _, host := range u.RemoteHosts {
			b.WriteString(remoteAccountDDL(payload.DatabaseName, u, host, hash, payload.Suspended))
		}
	}
	for _, u := range gone {
		for _, host := range live[u.Name] {
			fmt.Fprintf(&b, "DROP USER IF EXISTS '%s'@'%s';\n", u.Name, host)
		}
	}

	if b.Len() == 0 {
		return false, nil
	}

	if _, err := runSQL(ctx, cfg, withFlush(b.String())); err != nil {
		return false, err
	}

	return true, nil
}

// applyRemoteAccess brings remote access in line after an operation's own
// DDL: the certificate when a user connects remotely, the remote accounts,
// then the firewall when any remote account changed. A node that never had
// a remote user never runs nft. Returns the result error code on failure.
func (c *MariaDBCapability) applyRemoteAccess(ctx context.Context, payload Payload, gone []User) (string, error) {
	if payload.hasRemoteHosts() {
		if err := syncTLS(ctx, c.cfg, payload.TLSHostname); err != nil {
			return "tls_sync_failed", err
		}
	}

	changed, err := syncRemoteAccounts(ctx, c.cfg, payload, gone)
	if err != nil {
		return "remote_accounts_failed", err
	}

	if changed {
		if err := c.syncFirewall(ctx); err != nil {
			return "firewall_sync_failed", err
		}
	}

	return "", nil
}

// syncTLS copies the node hostname's certificate and key into the files
// the tenant instance presents (Config.TLSDir) and tells it to load them
// with FLUSH SSL, when they differ from what is there. The key is made
// readable by Config.TLSGroup, the group mariadbd runs as. A missing
// certificate is an error: remote access is refused without one.
func syncTLS(ctx context.Context, cfg Config, hostname string) error {
	if cfg.AcmeCertsRoot == "" || cfg.TLSDir == "" {
		return nil
	}

	changed := false
	for _, file := range []struct {
		name string
		mode os.FileMode
	}{{"fullchain.pem", 0o644}, {"privkey.pem", 0o640}} {
		source, err := os.ReadFile(filepath.Join(cfg.AcmeCertsRoot, hostname, file.name))
		if err != nil {
			return fmt.Errorf("reading the certificate for %s: %w", hostname, err)
		}

		target := filepath.Join(cfg.TLSDir, file.name)
		if current, err := os.ReadFile(target); err == nil && bytes.Equal(current, source) {
			continue
		}

		if err := atomicfile.WriteOwned(target, source, file.mode, cfg.TLSGroup); err != nil {
			return err
		}
		changed = true
	}

	if !changed {
		return nil
	}

	if _, err := runSQL(ctx, cfg, "FLUSH SSL;\n"); err != nil {
		return fmt.Errorf("loading the new certificate: %w", err)
	}

	return nil
}

// firewallElements renders the nft script that sets the firewall's two
// allow sets to hosts: the table and sets are declared (a no-op when they
// exist, so the rest of the table is untouched), both sets flushed, then
// filled, in one transaction. lib/firewall.sh declares the same sets and
// the rule that lets them reach the tenant port, and the firewall unit
// loads this file again at boot.
func firewallElements(hosts []netip.Addr) string {
	var v4, v6 []string
	for _, addr := range hosts {
		if addr.Is4() {
			v4 = append(v4, addr.String())
		} else {
			v6 = append(v6, addr.String())
		}
	}

	var b strings.Builder
	b.WriteString("# Managed by the LESta agent (database.tenant.v1): the addresses extra database users\n")
	b.WriteString("# may connect from. Rewritten after every database change; do not edit by hand.\n")
	b.WriteString("table inet lesta {\n")
	b.WriteString("    set db_remote_v4 {\n        type ipv4_addr;\n    }\n")
	b.WriteString("    set db_remote_v6 {\n        type ipv6_addr;\n    }\n")
	b.WriteString("}\n")
	b.WriteString("flush set inet lesta db_remote_v4\n")
	b.WriteString("flush set inet lesta db_remote_v6\n")
	if len(v4) > 0 {
		fmt.Fprintf(&b, "add element inet lesta db_remote_v4 { %s }\n", strings.Join(v4, ", "))
	}
	if len(v6) > 0 {
		fmt.Fprintf(&b, "add element inet lesta db_remote_v6 { %s }\n", strings.Join(v6, ", "))
	}

	return b.String()
}

// accountHostAddresses is every distinct account host on the server that is
// a single remote address, sorted: the addresses the firewall lets reach the
// tenant port. Local and non-address hosts ('localhost', '%') never open it.
func accountHostAddresses(ctx context.Context, cfg Config) ([]netip.Addr, error) {
	out, err := runSQL(ctx, cfg, "SELECT DISTINCT Host FROM mysql.user;\n")
	if err != nil {
		return nil, err
	}

	var addrs []netip.Addr
	for _, line := range strings.Split(out, "\n") {
		addr, err := netip.ParseAddr(strings.TrimSpace(line))
		if err != nil || addr.Zone() != "" || addr.IsLoopback() || addr.IsUnspecified() {
			continue
		}
		addrs = append(addrs, addr.Unmap())
	}

	sort.Slice(addrs, func(i, j int) bool { return addrs[i].Less(addrs[j]) })

	return addrs, nil
}

// syncFirewall rewrites Config.FirewallElementsPath from the server's
// account hosts and loads it with nft. Derived from the server, never from
// one database's payload, so every database's remote hosts stay open
// whichever one changed.
func (c *MariaDBCapability) syncFirewall(ctx context.Context) error {
	if c.cfg.FirewallElementsPath == "" {
		return nil
	}

	c.firewallMu.Lock()
	defer c.firewallMu.Unlock()

	addrs, err := accountHostAddresses(ctx, c.cfg)
	if err != nil {
		return fmt.Errorf("reading account hosts: %w", err)
	}

	if err := atomicfile.WriteOwned(c.cfg.FirewallElementsPath, []byte(firewallElements(addrs)), 0o640, ""); err != nil {
		return err
	}

	cmd := c.cfg.nftCommand(ctx, "-f", c.cfg.FirewallElementsPath)
	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("loading %s: %w: %s", c.cfg.FirewallElementsPath, err, strings.TrimSpace(string(out)))
	}

	return nil
}
