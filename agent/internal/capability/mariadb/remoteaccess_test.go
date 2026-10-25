package mariadb_test

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"fmt"
	"math/big"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/mariadb"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// writeTestCertificate writes a self-signed certificate for 127.0.0.1 and
// its key into dir as fullchain.pem and privkey.pem, the names both the
// acme root and the tenant TLS directory use.
func writeTestCertificate(t *testing.T, dir, commonName string) {
	t.Helper()

	key, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatal(err)
	}
	template := x509.Certificate{
		SerialNumber:          big.NewInt(time.Now().UnixNano()),
		Subject:               pkix.Name{CommonName: commonName},
		NotBefore:             time.Now().Add(-time.Hour),
		NotAfter:              time.Now().Add(24 * time.Hour),
		IPAddresses:           []net.IP{net.ParseIP("127.0.0.1")},
		KeyUsage:              x509.KeyUsageDigitalSignature | x509.KeyUsageCertSign,
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		BasicConstraintsValid: true,
		IsCA:                  true,
	}
	der, err := x509.CreateCertificate(rand.Reader, &template, &template, &key.PublicKey, key)
	if err != nil {
		t.Fatal(err)
	}
	keyDER, err := x509.MarshalECPrivateKey(key)
	if err != nil {
		t.Fatal(err)
	}

	if err := os.MkdirAll(dir, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "fullchain.pem"), pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: der}), 0o644); err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(dir, "privkey.pem"), pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: keyDER}), 0o600); err != nil {
		t.Fatal(err)
	}
}

// TestMariaDBCapability_RemoteAccessLifecycle proves remote access against
// a real, disposable tenant MariaDB instance started with a seeded
// certificate: each remote host gets its own TLS-only account copying the
// local password and grant, the node hostname's certificate replaces the
// seeded one, and rotations, access changes, suspension, removal and delete
// all reach the remote accounts and the firewall's allow list. nft itself
// is replaced by `true`; the rendered script is checked instead.
func TestMariaDBCapability_RemoteAccessLifecycle(t *testing.T) {
	requireRealMariaDB(t)

	const (
		databaseName = "lesta_1_shop"
		reportUser   = "lesta_1_shop_report"
		hostname     = "node.example.test"
	)

	prefix := t.TempDir()
	tlsDir := filepath.Join(prefix, "tenant-tls")
	acmeRoot := filepath.Join(prefix, "acme-certs")
	writeTestCertificate(t, tlsDir, "seeded")
	writeTestCertificate(t, filepath.Join(acmeRoot, hostname), hostname)
	seeded, err := os.ReadFile(filepath.Join(tlsDir, "fullchain.pem"))
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(filepath.Join(prefix, "seeded-ca.pem"), seeded, 0o644); err != nil {
		t.Fatal(err)
	}
	firewallPath := filepath.Join(prefix, "db-remote.nft")

	d := newDisposableMariaDB(t, "--ssl-cert="+filepath.Join(tlsDir, "fullchain.pem"), "--ssl-key="+filepath.Join(tlsDir, "privkey.pem"))
	cfg := d.Config
	cfg.AcmeCertsRoot = acmeRoot
	cfg.TLSDir = tlsDir
	cfg.FirewallElementsPath = firewallPath
	cfg.NftBinary = "true"
	capability := mariadb.New(cfg)
	ctx := context.Background()

	resourceID := newTestUUID()
	version := 1
	apply := func(t *testing.T, label string, operation protocol.Operation, payload map[string]any) {
		t.Helper()
		version++
		result, err := capability.Apply(ctx, newOp(operation, resourceID, newTestUUID(), version, payload))
		requireApplied(t, label, result, err)
	}
	withReport := func(payload map[string]any, access string, password *string, hosts ...string) map[string]any {
		u := map[string]any{"name": reportUser, "access": access}
		if password != nil {
			u["password"] = *password
		}
		if len(hosts) > 0 {
			u["remote_hosts"] = hosts
			payload["tls_hostname"] = hostname
		}
		payload["users"] = []map[string]any{u}
		return payload
	}
	sql := func(t *testing.T, script string) string {
		t.Helper()
		out, err := d.adminSQL(script)
		if err != nil {
			t.Fatal(err)
		}
		return strings.TrimSpace(out)
	}
	hashAt := func(t *testing.T, host string) string {
		t.Helper()
		return sql(t, fmt.Sprintf("SELECT authentication_string FROM mysql.user WHERE User = '%s' AND Host = '%s';", reportUser, host))
	}
	firewall := func(t *testing.T) string {
		t.Helper()
		content, err := os.ReadFile(firewallPath)
		if err != nil {
			t.Fatal(err)
		}
		return string(content)
	}

	password := randomHex(t, 24)

	t.Run("a create with remote hosts makes one TLS-only account per host with the local password and grant", func(t *testing.T) {
		version = 1
		result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), version,
			withReport(tenantPayload(databaseName, databaseName, strPtr(randomHex(t, 24)), false), "read_only", &password, "203.0.113.5", "2001:db8::5")))
		requireApplied(t, "create", result, err)

		local := hashAt(t, "127.0.0.1")
		for _, host := range []string{"203.0.113.5", "2001:db8::5"} {
			if got := hashAt(t, host); got != local || got == "" {
				t.Fatalf("%s should copy the local password hash %q, got %q", host, local, got)
			}
			create := sql(t, fmt.Sprintf("SHOW CREATE USER '%s'@'%s';", reportUser, host))
			if !strings.Contains(create, "REQUIRE SSL") {
				t.Fatalf("%s should require TLS: %s", host, create)
			}
			grants := sql(t, fmt.Sprintf("SHOW GRANTS FOR '%s'@'%s';", reportUser, host))
			if !strings.Contains(grants, "GRANT SELECT, SHOW VIEW ON `lesta_1_shop`.*") {
				t.Fatalf("%s should have the read-only grant: %s", host, grants)
			}
		}

		if content := firewall(t); !strings.Contains(content, "add element inet lesta db_remote_v4 { 203.0.113.5 }") ||
			!strings.Contains(content, "add element inet lesta db_remote_v6 { 2001:db8::5 }") {
			t.Fatalf("the firewall script should allow both hosts:\n%s", content)
		}
	})

	t.Run("the instance now presents the node hostname's certificate", func(t *testing.T) {
		if _, err := d.connectOverTLS(reportUser, password, databaseName, filepath.Join(acmeRoot, hostname, "fullchain.pem"), "SELECT 1;"); err != nil {
			t.Fatalf("a client trusting the node hostname's certificate should connect: %v", err)
		}
		if _, err := d.connectOverTLS(reportUser, password, databaseName, filepath.Join(prefix, "seeded-ca.pem"), "SELECT 1;"); err == nil {
			t.Fatal("a client trusting only the seeded certificate should no longer connect")
		}
	})

	t.Run("observe with remote hosts reports no drift", func(t *testing.T) {
		result, err := capability.Apply(ctx, newOp(protocol.OperationObserve, resourceID, newTestUUID(), version,
			withReport(tenantPayload(databaseName, databaseName, nil, false), "read_only", nil, "203.0.113.5", "2001:db8::5")))
		requireApplied(t, "observe", result, err)
	})

	t.Run("observe notices a remote account dropped by hand", func(t *testing.T) {
		sql(t, fmt.Sprintf("DROP USER '%s'@'2001:db8::5';", reportUser))
		result, err := capability.Apply(ctx, newOp(protocol.OperationObserve, resourceID, newTestUUID(), version,
			withReport(tenantPayload(databaseName, databaseName, nil, false), "read_only", nil, "203.0.113.5", "2001:db8::5")))
		requireStatus(t, "observe after drop", result, err, protocol.StatusDegraded)
	})

	newPassword := randomHex(t, 24)

	t.Run("a rotation and an access change reach every remote account, and a dropped host goes", func(t *testing.T) {
		apply(t, "rotate", protocol.OperationUpdate, withReport(tenantPayload(databaseName, databaseName, nil, false), "full", &newPassword, "203.0.113.5", "198.51.100.7"))

		local := hashAt(t, "127.0.0.1")
		for _, host := range []string{"203.0.113.5", "198.51.100.7"} {
			if got := hashAt(t, host); got != local {
				t.Fatalf("%s should carry the rotated hash", host)
			}
			if grants := sql(t, fmt.Sprintf("SHOW GRANTS FOR '%s'@'%s';", reportUser, host)); !strings.Contains(grants, "GRANT ALL PRIVILEGES ON `lesta_1_shop`.*") {
				t.Fatalf("%s should have the full grant now: %s", host, grants)
			}
		}
		if got := hashAt(t, "2001:db8::5"); got != "" {
			t.Fatal("the host no longer listed should be dropped")
		}
		if content := firewall(t); strings.Contains(content, "2001:db8::5") || !strings.Contains(content, "198.51.100.7, 203.0.113.5") {
			t.Fatalf("the firewall script should follow the hosts:\n%s", content)
		}
	})

	t.Run("suspend leaves remote accounts without grants and unsuspend gives them back", func(t *testing.T) {
		apply(t, "suspend", protocol.OperationSuspend, withReport(tenantPayload(databaseName, databaseName, nil, true), "full", nil, "203.0.113.5", "198.51.100.7"))
		if grants := sql(t, fmt.Sprintf("SHOW GRANTS FOR '%s'@'203.0.113.5';", reportUser)); strings.Contains(grants, "ON `lesta_1_shop`") {
			t.Fatalf("a suspended database's remote account should have no grant on it: %s", grants)
		}

		apply(t, "unsuspend", protocol.OperationUnsuspend, withReport(tenantPayload(databaseName, databaseName, nil, false), "full", nil, "203.0.113.5", "198.51.100.7"))
		if grants := sql(t, fmt.Sprintf("SHOW GRANTS FOR '%s'@'203.0.113.5';", reportUser)); !strings.Contains(grants, "GRANT ALL PRIVILEGES ON `lesta_1_shop`.*") {
			t.Fatalf("unsuspend should grant the remote account again: %s", grants)
		}
	})

	t.Run("removing every host drops the remote accounts and empties the allow list", func(t *testing.T) {
		apply(t, "local only", protocol.OperationUpdate, withReport(tenantPayload(databaseName, databaseName, nil, false), "full", nil))
		if count := sql(t, fmt.Sprintf("SELECT COUNT(*) FROM mysql.user WHERE User = '%s';", reportUser)); count != "1" {
			t.Fatalf("only the local account should be left, count %s", count)
		}
		if content := firewall(t); strings.Contains(content, "add element") {
			t.Fatalf("the allow list should be empty:\n%s", content)
		}
	})

	t.Run("deleting the database drops the remote accounts of its users", func(t *testing.T) {
		apply(t, "remote again", protocol.OperationUpdate, withReport(tenantPayload(databaseName, databaseName, nil, false), "full", nil, "203.0.113.5"))
		apply(t, "delete", protocol.OperationDelete, tenantPayload(databaseName, databaseName, nil, false))
		if count := sql(t, "SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'lesta\\_1\\_shop%';"); count != "0" {
			t.Fatalf("every account of the database should be gone, count %s", count)
		}
		if content := firewall(t); strings.Contains(content, "add element") {
			t.Fatalf("the allow list should be empty after the delete:\n%s", content)
		}
	})
}

// TestMariaDBCapability_RemoteAccessNeedsTheCertificate checks that a remote
// user is refused when the node hostname's certificate is not there to copy,
// before any remote account exists.
func TestMariaDBCapability_RemoteAccessNeedsTheCertificate(t *testing.T) {
	requireRealMariaDB(t)

	prefix := t.TempDir()
	d := newDisposableMariaDB(t)
	cfg := d.Config
	cfg.AcmeCertsRoot = filepath.Join(prefix, "acme-certs")
	cfg.TLSDir = filepath.Join(prefix, "tenant-tls")
	capability := mariadb.New(cfg)

	payload := tenantPayload("lesta_1_shop", "lesta_1_shop", strPtr(randomHex(t, 24)), false)
	payload["users"] = []map[string]any{{"name": "lesta_1_shop_report", "access": "full", "password": randomHex(t, 24), "remote_hosts": []string{"203.0.113.5"}}}
	payload["tls_hostname"] = "node.example.test"
	result, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, payload))
	requireStatus(t, "create without certificate", result, err, protocol.StatusFailed)
	requireErrorCode(t, "create without certificate", result, "tls_sync_failed")

	if out, _ := d.adminSQL("SELECT COUNT(*) FROM mysql.user WHERE User = 'lesta_1_shop_report' AND Host <> '127.0.0.1';"); strings.TrimSpace(out) != "0" {
		t.Fatalf("no remote account should exist, count %q", out)
	}
}

// TestParsePayload_RemoteHostRejections checks the agent's own validation
// of remote hosts, independent of the panel's.
func TestParsePayload_RemoteHostRejections(t *testing.T) {
	password := randomHex(t, 24)
	many := make([]string, 21)
	for i := range many {
		many[i] = fmt.Sprintf("203.0.113.%d", i+1)
	}

	cases := []struct {
		name     string
		hosts    []string
		hostname string
		code     string
	}{
		{"a range", []string{"203.0.113.0/24"}, "node.example.test", "invalid_remote_host"},
		{"a hostname", []string{"db.example.com"}, "node.example.test", "invalid_remote_host"},
		{"a wildcard", []string{"%"}, "node.example.test", "invalid_remote_host"},
		{"loopback", []string{"127.0.0.1"}, "node.example.test", "invalid_remote_host"},
		{"IPv6 loopback", []string{"::1"}, "node.example.test", "invalid_remote_host"},
		{"unspecified", []string{"0.0.0.0"}, "node.example.test", "invalid_remote_host"},
		{"link-local", []string{"fe80::1"}, "node.example.test", "invalid_remote_host"},
		{"multicast", []string{"224.0.0.1"}, "node.example.test", "invalid_remote_host"},
		{"IPv4 written as IPv6", []string{"::ffff:203.0.113.5"}, "node.example.test", "invalid_remote_host"},
		{"IPv6 not in canonical form", []string{"2001:DB8:0:0:0:0:0:5"}, "node.example.test", "invalid_remote_host"},
		{"a quote", []string{"203.0.113.5'"}, "node.example.test", "invalid_remote_host"},
		{"the same host twice", []string{"203.0.113.5", "203.0.113.5"}, "node.example.test", "duplicate_remote_host"},
		{"too many hosts", many, "node.example.test", "too_many_remote_hosts"},
		{"no tls hostname", []string{"203.0.113.5"}, "", "tls_hostname_required"},
		{"a tls hostname that is a path", []string{"203.0.113.5"}, "../etc", "tls_hostname_required"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			payload := tenantPayload("lesta_1_shop", "lesta_1_shop", strPtr(password), false)
			payload["users"] = []map[string]any{{"name": "lesta_1_shop_report", "access": "full", "password": password, "remote_hosts": tc.hosts}}
			if tc.hostname != "" {
				payload["tls_hostname"] = tc.hostname
			}
			raw, err := json.Marshal(payload)
			if err != nil {
				t.Fatal(err)
			}

			_, err = mariadb.ParsePayload(raw)
			verr, ok := err.(*mariadb.ValidationError)
			if !ok || verr.Code != tc.code {
				t.Fatalf("expected %s, got %v", tc.code, err)
			}
		})
	}
}
