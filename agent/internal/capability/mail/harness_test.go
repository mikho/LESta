package mail_test

import (
	"crypto/rand"
	"encoding/hex"
	"fmt"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/mail"
)

// requireRealExim skips the calling test, with a clear reason, if exim,
// doveadm, sievec, or openssl aren't all on PATH. No build tag: `go test
// ./...` runs everything not requiring these unconditionally, and only this
// real-capability contract suite self-skips here, mirroring bind9's own
// requireRealBind9/mariadb's own requireRealMariaDB.
func requireRealExim(t *testing.T) {
	t.Helper()

	for _, bin := range []string{"exim", "doveadm", "sievec", "openssl"} {
		if _, err := exec.LookPath(bin); err != nil {
			t.Skipf("%s is not installed on PATH; skipping the real MailCapability contract suite", bin)
		}
	}
}

// disposableExim is a fully disposable, per-test Exim instance: its own
// spool/config/data directories under t.TempDir(), listening on an
// ephemeral loopback TCP port, running as this test process's own real OS
// identity (never a fixed system exim_user), matching bind9's/mariadb's own
// zero-sudo, zero-systemd disposable-instance precedent exactly.
//
// Dovecot itself is deliberately never started by this harness: confirmed
// directly that the only local Dovecot available (Homebrew's 2.4.x) uses a
// structurally incompatible config format from the real Ubuntu 24.04/26.04
// production target (2.3.x classic syntax) -- `doveconf` on this machine
// hard-rejects a 2.3-style config outright ("first setting must be
// dovecot_config_version"), so no local instance of it could honestly stand
// in for the real target's own config parser. This does not block
// meaningful local coverage, though: MailCapability's own reload step for
// Dovecot is fully overridable (Config.DovecotReloadCommand, mirroring
// bind9's own ReloadCommand seam), and every other real tool this
// capability actually shells out to -- exim itself, `doveadm pw` (password
// hashing), `sievec` (Sieve script compilation), and `openssl` (DKIM
// keypair generation) -- runs standalone, needing no full Dovecot daemon or
// its own version-specific config at all. Real end-to-end LMTP delivery and
// IMAP retrieval, and the real Dovecot structural config's own syntax, are
// proven by real CI against actual Ubuntu packages once an installer phase
// exists, mirroring this project's own established "local Mac testing is a
// partial, best-effort proof; real CI on the real target OS is the
// authoritative one" precedent (already true today for e.g. MariaDB's own
// version gap between this machine and the pinned production package).
type disposableExim struct {
	Config mail.Config
	prefix string
	port   int
}

func newDisposableExim(t *testing.T) *disposableExim {
	t.Helper()

	prefix := t.TempDir()

	dataDir := filepath.Join(prefix, "data")
	spoolDir := filepath.Join(prefix, "spool")
	dkimRoot := filepath.Join(prefix, "dkim")
	sieveDir := filepath.Join(prefix, "sieve")
	logDir := filepath.Join(prefix, "log")

	for _, dir := range []string{dataDir, spoolDir, dkimRoot, sieveDir, logDir} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatalf("creating %s: %v", dir, err)
		}
	}

	passwdPath := filepath.Join(prefix, "passwd")
	if err := os.WriteFile(passwdPath, nil, 0o644); err != nil {
		t.Fatalf("creating empty passwd file: %v", err)
	}

	for _, name := range []string{"domains.list", "accounts.list", "antivirus.list", "antispam.list", "dkim_keys.list", "catchall.list"} {
		if err := os.WriteFile(filepath.Join(dataDir, name), nil, 0o644); err != nil {
			t.Fatalf("creating empty %s: %v", name, err)
		}
	}

	port := freePort(t)
	pidPath := filepath.Join(prefix, "exim.pid")

	currentUser := currentUsername(t)
	currentGroup := currentGroupname(t)

	// The lmtp transport below deliberately omits dkim_domain/dkim_selector/
	// dkim_private_key, unlike the real structural config an operator would
	// write in production. Confirmed directly: Exim refuses to start as a
	// daemon (-bd) at all with those options present under an untrusted -C
	// invocation ("exim user lost privilege for using -C option" followed
	// by "option \"dkim_domain\" unknown"), a real Exim security feature
	// that only trusts an alternate config file from root or the binary's
	// own compiled-in exim_user for certain "dangerous" options -- neither
	// of which this disposable, non-root test process is. `exim -bV`/`-bS`
	// (used by TestMailCapability_DKIM's own real signing proof, and by the
	// manual proof this design is based on) are NOT subject to this same
	// restriction, only long-running daemon mode is; a real production node
	// starts Exim via its own real config (no -C at all, started by
	// systemd as root), so this restriction never applies there. This is a
	// disclosed local-testing-harness limitation, not a production design
	// change.
	staticConf := fmt.Sprintf(`exim_user = %s
exim_group = %s
spool_directory = %s
log_file_path = %s/%%slog
pid_file_path = %s
primary_hostname = mail.lesta-test.invalid
daemon_smtp_ports = %d
local_interfaces = <; 127.0.0.1

domainlist local_domains = lsearch;%s/domains.list

acl_smtp_rcpt = acl_check_rcpt
acl_smtp_mail = acl_check_mail

begin acl

acl_check_mail:
  accept

acl_check_rcpt:
  accept  domains = +local_domains
          condition = ${lookup{$local_part@$domain}lsearch{%s/accounts.list}{yes}{no}}
  deny    domains = +local_domains
          message = "no such mailbox"
  accept  hosts = :
  deny    message = "relay not permitted"

begin routers

lesta_virtual_router:
  driver = accept
  domains = +local_domains
  condition = ${lookup{$local_part@$domain}lsearch{%s/accounts.list}{yes}{no}}
  transport = lesta_lmtp_delivery

begin transports

lesta_lmtp_delivery:
  driver = lmtp
  socket = %s/dovecot-lmtp.sock

begin authenticators
`,
		currentUser, currentGroup, spoolDir, logDir, pidPath, port, dataDir,
		dataDir, dataDir, prefix)

	staticConfPath := filepath.Join(prefix, "exim.conf")
	if err := os.WriteFile(staticConfPath, []byte(staticConf), 0o644); err != nil {
		t.Fatalf("writing static exim config: %v", err)
	}

	cfg := mail.Config{
		EximStaticConfPath: staticConfPath,
		EximDataDir:        dataDir,
		EximPIDFile:        pidPath,
		EximListenAddress:  "127.0.0.1",
		EximListenPort:     port,

		DovecotConfPath:      minimalDovecotConf(t, prefix),
		DovecotPasswdPath:    passwdPath,
		DovecotReloadCommand: []string{"true"},

		SieveDir: sieveDir,

		DKIMKeyRoot: dkimRoot,

		StateRoot: filepath.Join(prefix, "state"),
	}

	d := &disposableExim{Config: cfg, prefix: prefix, port: port}

	d.start(t)
	t.Cleanup(func() { d.stop() })

	return d
}

// minimalDovecotConf writes a bootstrap Dovecot config just complete enough
// for `sievec -c <path>` to resolve mail_uid/mail_gid and run at all
// (confirmed directly: it otherwise fails closed with "Unknown UNIX UID
// user"). This is NOT a stand-in for the real production structural
// config (see this file's own top comment); it exists solely to make the
// real sievec binary itself runnable in this disposable test environment.
func minimalDovecotConf(t *testing.T, prefix string) string {
	t.Helper()

	path := filepath.Join(prefix, "dovecot-sieve-bootstrap.conf")
	content := fmt.Sprintf("dovecot_config_version = %s\nmail_uid = %s\nmail_gid = %s\n", dovecotConfigVersion(t), currentUsername(t), currentGroupname(t))

	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatalf("writing minimal dovecot sieve-bootstrap config: %v", err)
	}

	return path
}

func dovecotConfigVersion(t *testing.T) string {
	t.Helper()

	out, err := exec.Command("dovecot", "--version").Output()
	if err != nil {
		t.Fatalf("running dovecot --version: %v", err)
	}

	return strings.Fields(string(out))[0]
}

func currentUsername(t *testing.T) string {
	t.Helper()

	out, err := exec.Command("id", "-un").Output()
	if err != nil {
		t.Fatalf("running id -un: %v", err)
	}

	return strings.TrimSpace(string(out))
}

func currentGroupname(t *testing.T) string {
	t.Helper()

	out, err := exec.Command("id", "-gn").Output()
	if err != nil {
		t.Fatalf("running id -gn: %v", err)
	}

	return strings.TrimSpace(string(out))
}

func (d *disposableExim) start(t *testing.T) {
	t.Helper()

	cmd := exec.Command("exim", "-C", d.Config.EximStaticConfPath, "-bd")

	if err := cmd.Start(); err != nil {
		t.Fatalf("starting disposable exim: %v", err)
	}

	t.Cleanup(func() {
		if cmd.Process != nil {
			_ = cmd.Process.Kill()
			_, _ = cmd.Process.Wait()
		}
	})

	deadline := time.Now().Add(10 * time.Second)
	for time.Now().Before(deadline) {
		conn, err := net.Dial("tcp", net.JoinHostPort("127.0.0.1", strconv.Itoa(d.port)))
		if err == nil {
			_ = conn.Close()

			return
		}

		time.Sleep(50 * time.Millisecond)
	}

	t.Fatalf("disposable exim never started listening on port %d", d.port)
}

func (d *disposableExim) stop() {
	pid, err := readPIDForTest(d.Config.EximPIDFile)
	if err != nil {
		return
	}

	proc, err := os.FindProcess(pid)
	if err != nil {
		return
	}

	_ = proc.Signal(syscall.SIGTERM)
}

func readPIDForTest(path string) (int, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return 0, err
	}

	return strconv.Atoi(strings.TrimSpace(string(raw)))
}

// freePort allocates a real, momentarily-bound-then-released ephemeral
// loopback port, mirroring every other disposable-instance harness in this
// module.
func freePort(t *testing.T) int {
	t.Helper()

	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("finding a free port: %v", err)
	}
	defer l.Close()

	return l.Addr().(*net.TCPAddr).Port
}

func randomHex(t *testing.T, n int) string {
	t.Helper()

	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		t.Fatalf("generating random hex: %v", err)
	}

	return hex.EncodeToString(b)
}
