package identity_test

import (
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

	"github.com/mikho/LESta/agent/internal/capability/identity"
)

// requireRealSshd skips the calling test, with a clear reason, if sshd isn't
// on PATH. Mirrors nginx_test.go's own requireRealNginx.
func requireRealSshd(t *testing.T) {
	t.Helper()

	if _, err := exec.LookPath("sshd"); err != nil {
		t.Skip("sshd is not installed on PATH; skipping the real IdentityCapability SFTP contract suite")
	}
}

// disposableSshd is a fully disposable, per-test sshd process: its own
// -f-relocated config under t.TempDir(), listening on an ephemeral loopback
// port, reloaded via SIGHUP instead of systemctl. UsePAM/StrictModes are
// both disabled so this runs identically on this Mac, a Multipass VM, or a
// GitHub Actions Ubuntu runner with no host-specific PAM stack assumed.
//
// ChrootDirectory's own real ownership requirement (root:root, not group/
// world-writable, all the way up the path) is NOT satisfied by anything
// this harness sets up: `sshd -t` only checks syntax, never that
// requirement, so create/update/delete's own validate/activate/reload/
// health pipeline is real and fully exercised here, but an actual
// authenticated SFTP session succeeding end to end needs a real root-owned
// chroot tree this unprivileged harness cannot provide -- that end-to-end
// proof is step 2b's own real installer CI, run with root on a disposable
// Ubuntu instance.
type disposableSshd struct {
	Config  identity.Config
	Port    int
	pidPath string
}

func newDisposableSshd(t *testing.T) *disposableSshd {
	t.Helper()

	root := t.TempDir()
	sftpConfigDir := filepath.Join(root, "lesta.d")
	authorizedKeysDir := filepath.Join(root, "authorized_keys")
	accountsRoot := filepath.Join(root, "accounts")
	stateRoot := filepath.Join(root, "state")

	for _, dir := range []string{sftpConfigDir, authorizedKeysDir, accountsRoot, stateRoot} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatalf("creating %s: %v", dir, err)
		}
	}

	hostKeyPath := filepath.Join(root, "ssh_host_ed25519_key")

	keygen := exec.Command("ssh-keygen", "-q", "-N", "", "-t", "ed25519", "-f", hostKeyPath)
	if out, err := keygen.CombinedOutput(); err != nil {
		t.Fatalf("generating disposable sshd host key: %v: %s", err, out)
	}

	port := freePort(t)
	pidPath := filepath.Join(root, "sshd.pid")

	confPath := filepath.Join(root, "sshd_config")
	confBody := fmt.Sprintf(`Port %d
ListenAddress 127.0.0.1
HostKey %s
PidFile %s
UsePAM no
StrictModes no
PasswordAuthentication no
PermitRootLogin no
Subsystem sftp internal-sftp
AuthorizedKeysFile %s/%%u
Include %s/*.conf
`,
		port,
		hostKeyPath,
		pidPath,
		authorizedKeysDir,
		sftpConfigDir,
	)

	if err := os.WriteFile(confPath, []byte(confBody), 0o644); err != nil {
		t.Fatalf("writing disposable sshd_config: %v", err)
	}

	d := &disposableSshd{
		Port:    port,
		pidPath: pidPath,
		Config: identity.Config{
			AccountsRoot:      accountsRoot,
			SftpConfigDir:     sftpConfigDir,
			AuthorizedKeysDir: authorizedKeysDir,
			StateRoot:         stateRoot,
			SshdConfigPath:    confPath,
			Port:              port,
		},
	}

	d.start(t)

	t.Cleanup(func() { d.stop() })

	return d
}

func (d *disposableSshd) start(t *testing.T) {
	t.Helper()

	// No -D: sshd's own default behavior is to validate, fork into the
	// background, and exit the parent -- identical in shape to nginx's own
	// default daemonizing start, and why waitForPidFile below is needed
	// (this Command's own Run() already returned by the time the real
	// daemon has written its pid file).
	//
	// exec.LookPath, not a bare "sshd": Apple's own OpenSSH build (macOS)
	// refuses to run at all via a relative/PATH-resolved name ("sshd
	// requires execution with an absolute path"), unlike Linux's -- found
	// running this suite's own first-ever root-independent sshd-dependent
	// test locally (every other one skips before reaching this point
	// unless already running as root).
	sshdPath, err := exec.LookPath("sshd")
	if err != nil {
		t.Fatalf("resolving sshd's own absolute path: %v", err)
	}

	cmd := exec.Command(sshdPath, "-f", d.Config.SshdConfigPath)
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("starting disposable sshd: %v: %s", err, out)
	}

	if err := waitForPidFile(d.pidPath, 5*time.Second); err != nil {
		t.Fatalf("disposable sshd never wrote its pid file: %v", err)
	}
}

func (d *disposableSshd) pid() int {
	raw, err := os.ReadFile(d.pidPath)
	if err != nil {
		return 0
	}

	pid, err := strconv.Atoi(strings.TrimSpace(string(raw)))
	if err != nil {
		return 0
	}

	return pid
}

// reloadConfig sets Config.ReloadPID to this disposable instance's own
// current real pid, read fresh each time: IdentityCapability itself never
// restarts sshd, only signals it, so the pid stays stable across an entire
// test's lifetime, but reading it fresh rather than caching it at
// newDisposableSshd time keeps this harness honest about that being a real
// runtime fact, not an assumption.
func (d *disposableSshd) reloadConfig() identity.Config {
	cfg := d.Config
	cfg.ReloadPID = d.pid()

	return cfg
}

func (d *disposableSshd) stop() {
	if pid := d.pid(); pid != 0 {
		_ = syscall.Kill(pid, syscall.SIGTERM)
	}
}

// freePort binds a loopback listener momentarily to obtain an unused port,
// then releases it before sshd binds the same port. Mirrors nginx_test.go's
// own freePort exactly.
func freePort(t *testing.T) int {
	t.Helper()

	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("finding a free port: %v", err)
	}
	defer l.Close()

	return l.Addr().(*net.TCPAddr).Port
}

// waitForPidFile polls until a pid file exists and names a running process,
// or timeout elapses. Mirrors nginx_test.go's own waitForPidFile exactly.
func waitForPidFile(pidPath string, timeout time.Duration) error {
	deadline := time.Now().Add(timeout)

	var lastErr error

	for time.Now().Before(deadline) {
		raw, err := os.ReadFile(pidPath)
		if err != nil {
			lastErr = err
			time.Sleep(50 * time.Millisecond)

			continue
		}

		pid, err := strconv.Atoi(strings.TrimSpace(string(raw)))
		if err != nil {
			lastErr = fmt.Errorf("pid file %s has unexpected content %q: %w", pidPath, raw, err)
			time.Sleep(50 * time.Millisecond)

			continue
		}

		proc, err := os.FindProcess(pid)
		if err != nil {
			lastErr = err
			time.Sleep(50 * time.Millisecond)

			continue
		}

		if err := proc.Signal(syscall.Signal(0)); err != nil {
			lastErr = fmt.Errorf("pid %d from %s is not running: %w", pid, pidPath, err)
			time.Sleep(50 * time.Millisecond)

			continue
		}

		return nil
	}

	return fmt.Errorf("pid file %s never named a running process within %s (last error: %v)", pidPath, timeout, lastErr)
}
