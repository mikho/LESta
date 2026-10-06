package phpfpm

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"testing"
	"time"
)

// realPhpFpmBinary returns the first real php-fpm binary found on PATH,
// preferring a version-suffixed name (Ubuntu/ondrej packaging convention)
// over a bare "php-fpm", and the version string it corresponds to. Returns
// ("", "") if none is found -- this Mac, for instance, ships no php-fpm
// binary of any kind (confirmed directly, not assumed).
func realPhpFpmBinary(t *testing.T) (string, string) {
	t.Helper()

	for _, version := range []string{"8.3", "8.4", "8.2", "8.1"} {
		if path, err := exec.LookPath(phpFpmBinary(version)); err == nil {
			return path, version
		}
	}

	if path, err := exec.LookPath("php-fpm"); err == nil {
		return path, ""
	}

	return "", ""
}

// requireRealPhpFpm also requires root and useradd/userdel: every pool
// (the placeholder and the account pool under test) names a real user and
// group, which php-fpm resolves even when not running as root.
func requireRealPhpFpm(t *testing.T) (string, string) {
	t.Helper()

	if os.Geteuid() != 0 {
		t.Skip("not running as root; skipping the real disposable php-fpm suite")
	}

	for _, bin := range []string{"useradd", "userdel"} {
		if _, err := exec.LookPath(bin); err != nil {
			t.Skipf("%s is not installed on PATH; skipping the real disposable php-fpm suite", bin)
		}
	}

	path, version := realPhpFpmBinary(t)
	if path == "" {
		t.Skip("no php-fpm binary is installed on PATH; skipping the real disposable php-fpm suite")
	}

	return path, version
}

// disposablePhpFpm is a fully disposable, per-test php-fpm instance: its
// own poolDir/fpmConfigPath under t.TempDir(), listening only via the pool
// fragments this test itself writes (no pre-existing pool). Mirrors
// nginx_test.go's own disposable-nginx harness shape.
type disposablePhpFpm struct {
	Config   Config
	Version  string
	Username string
	binary   string
	pidPath  string
	cmd      *exec.Cmd
}

func newDisposablePhpFpm(t *testing.T, binary, version string) *disposablePhpFpm {
	t.Helper()

	username := fmt.Sprintf("lesta-t%d", os.Getpid())
	if out, err := exec.Command("useradd", "--system", "--user-group", "--no-create-home", "--shell", "/usr/sbin/nologin", username).CombinedOutput(); err != nil {
		t.Fatalf("useradd %s: %v: %s", username, err, out)
	}
	t.Cleanup(func() { _ = exec.Command("userdel", username).Run() })

	root := t.TempDir()
	poolBaseDir := filepath.Join(root, "php")
	fpmDir := filepath.Join(poolBaseDir, version, "fpm")
	poolDir := filepath.Join(fpmDir, "pool.d")
	// Sockets get their own short temp dir: t.TempDir() embeds the test
	// name, and <root>/sockets/<version>/<uuid>.sock would pass the 108-byte
	// unix socket path limit.
	socketRoot, err := os.MkdirTemp("", "fpm")
	if err != nil {
		t.Fatalf("creating socket root: %v", err)
	}
	t.Cleanup(func() { _ = os.RemoveAll(socketRoot) })
	accountsRoot := filepath.Join(root, "accounts")
	stateRoot := filepath.Join(root, "state")

	for _, dir := range []string{poolDir, filepath.Join(socketRoot, version), accountsRoot, stateRoot} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatalf("creating %s: %v", dir, err)
		}
	}

	pidPath := filepath.Join(fpmDir, "php-fpm.pid")
	errorLog := filepath.Join(fpmDir, "error.log")

	fpmConfBody := fmt.Sprintf(`[global]
pid = %s
error_log = %s
daemonize = no

[www-placeholder]
listen = %s
user = %s
group = %s
pm = static
pm.max_children = 1

include=%s/*.conf
`, pidPath, errorLog, filepath.Join(root, "placeholder.sock"), username, username, poolDir)

	fpmConfPath := filepath.Join(fpmDir, "php-fpm.conf")
	if err := os.WriteFile(fpmConfPath, []byte(fpmConfBody), 0o644); err != nil {
		t.Fatalf("writing disposable php-fpm.conf: %v", err)
	}

	d := &disposablePhpFpm{
		Version:  version,
		Username: username,
		binary:   binary,
		pidPath:  pidPath,
		Config: Config{
			PoolBaseDir:  poolBaseDir,
			AccountsRoot: accountsRoot,
			SocketRoot:   socketRoot,
			StateRoot:    stateRoot,
		},
	}

	d.start(t)
	t.Cleanup(func() { d.stop() })

	return d
}

func (d *disposablePhpFpm) start(t *testing.T) {
	t.Helper()

	fpmConfPath := d.Config.fpmConfigPath(d.Version)

	// daemonize = no keeps php-fpm in the foreground, so it is started and
	// left running (never waited on here), and stop() reaps it.
	d.cmd = exec.Command(d.binary, "-y", fpmConfPath)
	output := &strings.Builder{}
	d.cmd.Stdout = output
	d.cmd.Stderr = output
	if err := d.cmd.Start(); err != nil {
		t.Fatalf("starting disposable php-fpm: %v", err)
	}

	if err := waitForPidFile(d.pidPath, 5*time.Second); err != nil {
		d.stop()
		t.Fatalf("disposable php-fpm never wrote its pid file: %v: %s", err, output.String())
	}

	// No systemd unit manages this instance: reload and liveness go to its
	// own master directly (SIGUSR2 is php-fpm's graceful reload).
	pid := strconv.Itoa(d.cmd.Process.Pid)
	d.Config.ReloadCommand = []string{"kill", "-USR2", pid}
	d.Config.IsActiveCommand = []string{"kill", "-0", pid}
}

func (d *disposablePhpFpm) stop() {
	if d.cmd == nil || d.cmd.Process == nil {
		return
	}

	_ = d.cmd.Process.Signal(syscall.SIGTERM)
	_ = d.cmd.Wait()
}

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
