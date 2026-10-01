package phpfpm

import (
	"context"
	"os/exec"
)

// supportedPhpVersions is this capability's own bounded allow-list, mirroring
// Laravel's App\Enums\PhpVersion exactly (both sides independently enforce
// the identical bounded set; neither trusts the other to have already
// checked). A tenant never gets to name an arbitrary version string that
// would otherwise select an arbitrary binary/config path.
var supportedPhpVersions = map[string]bool{
	"8.1": true,
	"8.2": true,
	"8.3": true,
	"8.4": true,
}

// Config parameterizes PhpFpmCapability by root paths and invocation
// details, so the identical implementation runs against a real
// ondrej/php-packaged multi-version install in production or a disposable
// per-test instance of whichever single php-fpm version happens to be on
// this machine's own PATH.
type Config struct {
	// PoolBaseDir is the root every installed PHP version's own fpm tree
	// nests under (Ubuntu/ondrej packaging convention:
	// PoolBaseDir/<version>/fpm/pool.d/<resource_id>.conf,
	// PoolBaseDir/<version>/fpm/php-fpm.conf). Production: /etc/php.
	PoolBaseDir string
	// AccountsRoot is system.account-identity.v1's own chroot accounts
	// root (identity.Config's own field of the same name); this
	// capability never writes there, only reads the convention to
	// compute each domain's own per-domain docroot
	// (AccountsRoot/<account_id>/domains/<resource_id>/public) for the
	// pool's own php_admin_value[open_basedir]. Production:
	// /var/lib/lesta/web/accounts.
	AccountsRoot string
	// SocketRoot is the fixed prefix every pool's own listen socket
	// lives under (SocketRoot/<version>/<resource_id>.sock). Must stay
	// in lockstep with WebDomain::phpSocketPath()'s own identical,
	// independently-computed formula on the Laravel side -- neither
	// side ever sends the other its own socket path over the wire, both
	// compute the same fixed formula from the same two inputs
	// (php_version, resource_id). Production: /run/lesta-php.
	SocketRoot string
	// StateRoot is the root generation history nests under (e.g.
	// /var/lib/lesta/php-fpm). Generations live at
	// StateRoot/domains/<resource_id>/generations/<n>/.
	StateRoot string
	// SudoBinary, when non-empty, routes both real root-only php-fpm
	// invocations (validate's own `-t`, and reload's `systemctl reload
	// php<version>-fpm`) through "<SudoBinary> <resolved binary> ...".
	// Production sets this to "sudo": the real lesta-agent-daemon
	// systemd unit runs as the unprivileged lesta-agent user. Empty
	// means invoke directly, matching this package's own disposable
	// test harness.
	SudoBinary string
}

// phpFpmBinary resolves the real per-version php-fpm binary name (Ubuntu/
// ondrej packaging convention: php-fpm8.3, never a bare "php-fpm" that
// would ambiguously mean "whichever version happens to be default").
func phpFpmBinary(version string) string {
	return "php-fpm" + version
}

// poolDir returns PoolBaseDir/<version>/fpm/pool.d, this version's own
// live fragment directory.
func (c Config) poolDir(version string) string {
	return c.PoolBaseDir + "/" + version + "/fpm/pool.d"
}

// fpmConfigPath returns PoolBaseDir/<version>/fpm/php-fpm.conf, the real
// read-only main config validate.go scans for this version's own
// `include=<poolDir>/*.conf` line.
func (c Config) fpmConfigPath(version string) string {
	return c.PoolBaseDir + "/" + version + "/fpm/php-fpm.conf"
}

// socketPath returns SocketRoot/<version>/<resourceID>.sock, matching
// WebDomain::phpSocketPath()'s own identical formula.
func (c Config) socketPath(version, resourceID string) string {
	return c.SocketRoot + "/" + version + "/" + resourceID + ".sock"
}

// docroot returns AccountsRoot/<accountID>/domains/<resourceID>/public,
// matching the exact per-domain webroot layout web.nginx.v1/web.apache.v1
// resolve independently from the same two inputs.
func (c Config) docroot(accountID, resourceID string) string {
	return c.AccountsRoot + "/" + accountID + "/domains/" + resourceID + "/public"
}

// command builds the real php-fpm invocation for binary+args, routed
// through SudoBinary when set (validate's own `-t` needs to read every
// pool's own config, including other tenants' -- not a secret this
// process should be trusted with directly; reload needs to mutate a
// systemd unit).
func (c Config) command(ctx context.Context, binary string, args ...string) *exec.Cmd {
	if c.SudoBinary == "" {
		return exec.CommandContext(ctx, binary, args...)
	}

	return exec.CommandContext(ctx, c.SudoBinary, append([]string{binary}, args...)...)
}
