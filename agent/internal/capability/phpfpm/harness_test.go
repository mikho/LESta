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

func requireRealPhpFpm(t *testing.T) (string, string) {
	t.Helper()

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
	Config  Config
	Version string
	binary  string
	pidPath string
}

func newDisposablePhpFpm(t *testing.T, binary, version string) *disposablePhpFpm {
	t.Helper()

	root := t.TempDir()
	poolBaseDir := filepath.Join(root, "php")
	fpmDir := filepath.Join(poolBaseDir, version, "fpm")
	poolDir := filepath.Join(fpmDir, "pool.d")
	socketRoot := filepath.Join(root, "sockets")
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
user = nobody
group = nobody
pm = static
pm.max_children = 1

include=%s/*.conf
`, pidPath, errorLog, filepath.Join(root, "placeholder.sock"), poolDir)

	fpmConfPath := filepath.Join(fpmDir, "php-fpm.conf")
	if err := os.WriteFile(fpmConfPath, []byte(fpmConfBody), 0o644); err != nil {
		t.Fatalf("writing disposable php-fpm.conf: %v", err)
	}

	d := &disposablePhpFpm{
		Version: version,
		binary:  binary,
		pidPath: pidPath,
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

	cmd := exec.Command(d.binary, "-y", fpmConfPath)
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("starting disposable php-fpm: %v: %s", err, out)
	}

	if err := waitForPidFile(d.pidPath, 5*time.Second); err != nil {
		t.Fatalf("disposable php-fpm never wrote its pid file: %v", err)
	}
}

func (d *disposablePhpFpm) stop() {
	raw, err := os.ReadFile(d.pidPath)
	if err != nil {
		return
	}

	pid, err := strconv.Atoi(strings.TrimSpace(string(raw)))
	if err != nil {
		return
	}

	_ = syscall.Kill(pid, syscall.SIGTERM)
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
