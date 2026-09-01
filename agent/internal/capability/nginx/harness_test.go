package nginx_test

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

	"github.com/mikho/LESta/agent/internal/capability/nginx"
)

// requireRealNginx skips the calling test, with a clear reason, if nginx isn't
// on PATH. No build tag: `go test ./...` runs everything not requiring nginx
// unconditionally, and only the real-capability suite self-skips here.
func requireRealNginx(t *testing.T) {
	t.Helper()

	if _, err := exec.LookPath("nginx"); err != nil {
		t.Skip("nginx is not installed on PATH; skipping the real NginxCapability contract suite")
	}
}

// disposableNginx is a fully disposable, per-test nginx process: its own
// -p/-c-relocated working directory under t.TempDir(), listening on an
// ephemeral loopback port, reloaded via `nginx -s reload -p -c` instead of
// systemctl. Zero sudo, zero systemd, identical on this Mac, a Multipass VM, or
// a GitHub Actions Ubuntu runner.
type disposableNginx struct {
	Config  nginx.Config
	Prefix  string
	Port    int
	pidPath string
}

// newDisposableNginx starts a fresh nginx master process and registers its
// teardown via t.Cleanup. The returned Config is ready to hand to nginx.New.
func newDisposableNginx(t *testing.T) *disposableNginx {
	t.Helper()

	prefix := t.TempDir()
	liveDir := filepath.Join(prefix, "lesta.d")
	stateRoot := filepath.Join(prefix, "state")
	logsDir := filepath.Join(prefix, "logs")
	acmeChallengeDir := filepath.Join(prefix, "acme-http-01")

	for _, dir := range []string{liveDir, stateRoot, logsDir, acmeChallengeDir} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatalf("creating %s: %v", dir, err)
		}
	}

	port := freePort(t)

	pidPath := filepath.Join(logsDir, "nginx.pid")

	confPath := filepath.Join(prefix, "nginx.conf")
	confBody := fmt.Sprintf(`pid %s;
error_log %s;
events { worker_connections 64; }
http {
    default_type application/octet-stream;
    access_log %s;
    include %s;
}
`,
		pidPath,
		filepath.Join(logsDir, "error.log"),
		filepath.Join(logsDir, "access.log"),
		filepath.Join(liveDir, "*.conf"),
	)

	if err := os.WriteFile(confPath, []byte(confBody), 0o644); err != nil {
		t.Fatalf("writing disposable nginx.conf: %v", err)
	}

	d := &disposableNginx{
		Prefix:  prefix,
		Port:    port,
		pidPath: pidPath,
		Config: nginx.Config{
			LiveDir:          liveDir,
			StateRoot:        stateRoot,
			NginxConfPath:    confPath,
			NginxBinary:      "nginx",
			Prefix:           prefix,
			Port:             port,
			AcmeChallengeDir: acmeChallengeDir,
			SSLPort:          freePort(t),
		},
	}

	d.start(t)

	t.Cleanup(func() { d.stop() })

	return d
}

func (d *disposableNginx) args(extra ...string) []string {
	return append([]string{"-p", d.Prefix, "-c", d.Config.NginxConfPath}, extra...)
}

func (d *disposableNginx) start(t *testing.T) {
	t.Helper()

	cmd := exec.Command("nginx", d.args()...)
	out, err := cmd.CombinedOutput()
	if err != nil {
		t.Fatalf("starting disposable nginx: %v: %s", err, out)
	}

	// The instance starts with no vhost fragments at all (none have been
	// created yet), so nothing is listening on d.Port until the first create;
	// readiness here just confirms the master process itself came up, via its
	// own pid file.
	if err := waitForPidFile(d.pidPath, 5*time.Second); err != nil {
		t.Fatalf("disposable nginx never wrote its pid file: %v", err)
	}
}

// reload issues a real `-s reload` against this disposable instance directly
// (bypassing NginxCapability), used by failure-semantics tests that need to
// confirm the instance's own state independent of the capability under test.
func (d *disposableNginx) reload() error {
	out, err := exec.Command("nginx", d.args("-s", "reload")...).CombinedOutput()
	if err != nil {
		return fmt.Errorf("%w: %s", err, out)
	}

	return nil
}

func (d *disposableNginx) stop() {
	_ = exec.Command("nginx", d.args("-s", "stop")...).Run()
}

// freePort binds a loopback listener momentarily to obtain an unused port, then
// releases it before nginx binds the same port. A small time-of-check/time-of-
// use race is inherent to this approach; it is the standard, accepted way to
// pick an ephemeral port for a disposable test process.
func freePort(t *testing.T) int {
	t.Helper()

	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("finding a free port: %v", err)
	}
	defer l.Close()

	return l.Addr().(*net.TCPAddr).Port
}

// waitForPidFile polls until nginx's pid file exists and names a running
// process, or timeout elapses.
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
