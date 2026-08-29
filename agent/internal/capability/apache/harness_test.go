package apache_test

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

	"github.com/mikho/LESta/agent/internal/capability/apache"
)

// requireRealApache resolves which real Apache binary is available on PATH,
// trying "apache2" first (the real Ubuntu/Debian package name this phase's
// production Config targets) and falling back to "httpd" (this Mac's own
// build, confirmed present at /usr/sbin/httpd during this phase's own hands-on
// verification). It t.Skips (never t.Fatals) with a clear message if neither is
// found, so `go test ./...` never hard-fails on a bare dev machine, matching
// nginx's and bind9's own self-skip pattern exactly.
func requireRealApache(t *testing.T) string {
	t.Helper()

	for _, bin := range []string{"apache2", "httpd"} {
		if _, err := exec.LookPath(bin); err == nil {
			return bin
		}
	}

	t.Skip("neither apache2 nor httpd is installed on PATH; skipping the real ApacheCapability contract suite")

	return ""
}

// resolveModuleDir finds the directory Apache's own .so modules live under.
// The path differs by platform: Ubuntu/Debian's apache2-bin package installs
// under /usr/lib/apache2/modules, while this Mac's own bundled build (verified
// hands-on during this phase's own design work) installs under
// /usr/libexec/apache2. Checking which directory actually exists, rather than
// hardcoding one, is what makes the identical harness code work on both.
func resolveModuleDir(t *testing.T) string {
	t.Helper()

	for _, dir := range []string{"/usr/lib/apache2/modules", "/usr/libexec/apache2"} {
		if info, err := os.Stat(dir); err == nil && info.IsDir() {
			return dir
		}
	}

	t.Fatalf("no apache2 module directory found (tried /usr/lib/apache2/modules and /usr/libexec/apache2)")

	return ""
}

// resolveMPMModule picks event over prefork when both are available (Ubuntu's
// apache2 package ships mpm_event; this Mac's own build, verified hands-on,
// also ships mpm_event), falling back to prefork only if event's .so is
// genuinely absent from the resolved module directory.
func resolveMPMModule(t *testing.T, moduleDir string) (name, path string) {
	t.Helper()

	eventPath := filepath.Join(moduleDir, "mod_mpm_event.so")
	if _, err := os.Stat(eventPath); err == nil {
		return "mpm_event_module", eventPath
	}

	preforkPath := filepath.Join(moduleDir, "mod_mpm_prefork.so")
	if _, err := os.Stat(preforkPath); err == nil {
		return "mpm_prefork_module", preforkPath
	}

	t.Fatalf("neither mod_mpm_event.so nor mod_mpm_prefork.so found in %s", moduleDir)

	return "", ""
}

// disposableApache is a fully disposable, per-test apache2/httpd process: its
// own -d-relocated ServerRoot under t.TempDir(), listening on an ephemeral
// loopback port, reloaded via `<binary> -k graceful -d <prefix> -f <conf>`
// instead of systemctl. Its base config is synthesized entirely from scratch
// (never copying any real system apache2.conf, unlike nginx's and bind9's own
// disposable harnesses, which still copy their real config's *shape*): the
// production ApacheConfPath precondition this capability depends on is that
// the real file already contains an IncludeOptional line, which this harness's
// own from-scratch config satisfies directly. Zero sudo, zero systemd,
// identical on this Mac, a Multipass VM, or a GitHub Actions Ubuntu runner.
type disposableApache struct {
	Config  apache.Config
	Prefix  string
	Port    int
	binary  string
	pidPath string
}

// newDisposableApache starts a fresh apache2/httpd master process and
// registers its teardown via t.Cleanup. The returned Config is ready to hand
// to apache.New.
func newDisposableApache(t *testing.T) *disposableApache {
	t.Helper()

	binary := requireRealApache(t)
	moduleDir := resolveModuleDir(t)
	mpmName, mpmPath := resolveMPMModule(t, moduleDir)

	prefix := t.TempDir()
	liveDir := filepath.Join(prefix, "lesta.d")
	stateRoot := filepath.Join(prefix, "state")
	logsDir := filepath.Join(prefix, "logs")
	htdocsDir := filepath.Join(prefix, "htdocs")

	for _, dir := range []string{liveDir, stateRoot, logsDir, htdocsDir} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatalf("creating %s: %v", dir, err)
		}
	}

	port := freePort(t)

	pidPath := filepath.Join(prefix, "apache.pid")
	confPath := filepath.Join(prefix, "apache2.conf")

	confBody := fmt.Sprintf(`LoadModule unixd_module %s
LoadModule %s %s
LoadModule authz_core_module %s
LoadModule log_config_module %s
LoadModule mime_module %s
LoadModule dir_module %s
LoadModule asis_module %s
PidFile %s
Listen 127.0.0.1:%d
ErrorLog %s
DocumentRoot %s
IncludeOptional %s
`,
		filepath.Join(moduleDir, "mod_unixd.so"),
		mpmName, mpmPath,
		filepath.Join(moduleDir, "mod_authz_core.so"),
		filepath.Join(moduleDir, "mod_log_config.so"),
		filepath.Join(moduleDir, "mod_mime.so"),
		filepath.Join(moduleDir, "mod_dir.so"),
		filepath.Join(moduleDir, "mod_asis.so"),
		pidPath,
		port,
		filepath.Join(logsDir, "error.log"),
		htdocsDir,
		filepath.Join(liveDir, "*.conf"),
	)

	if err := os.WriteFile(confPath, []byte(confBody), 0o644); err != nil {
		t.Fatalf("writing disposable apache2.conf: %v", err)
	}

	d := &disposableApache{
		Prefix:  prefix,
		Port:    port,
		binary:  binary,
		pidPath: pidPath,
		Config: apache.Config{
			LiveDir:        liveDir,
			StateRoot:      stateRoot,
			ApacheConfPath: confPath,
			ApacheBinary:   binary,
			Prefix:         prefix,
			Port:           port,
			// Env intentionally empty: this from-scratch synthetic config
			// never references ${APACHE_RUN_USER} or any other apache2
			// envvars-style substitution, unlike the real production
			// apache2.conf this capability's ApacheConfPath normally points
			// at.
		},
	}

	d.start(t)

	t.Cleanup(func() { d.stop() })

	return d
}

func (d *disposableApache) args(extra ...string) []string {
	return append([]string{"-d", d.Prefix, "-f", d.Config.ApacheConfPath}, extra...)
}

func (d *disposableApache) start(t *testing.T) {
	t.Helper()

	cmd := exec.Command(d.binary, d.args("-k", "start")...)
	out, err := cmd.CombinedOutput()
	if err != nil {
		t.Fatalf("starting disposable apache: %v: %s", err, out)
	}

	// The instance starts with no vhost fragments at all (none have been
	// created yet), so nothing resource-specific is being served on d.Port
	// until the first create; readiness here just confirms the master process
	// itself came up, via its own pid file.
	if err := waitForPidFile(d.pidPath, 5*time.Second); err != nil {
		t.Fatalf("disposable apache never wrote its pid file: %v", err)
	}
}

// reload issues a real `-k graceful` against this disposable instance directly
// (bypassing ApacheCapability), used by failure-semantics tests that need to
// confirm the instance's own state independent of the capability under test.
func (d *disposableApache) reload() error {
	out, err := exec.Command(d.binary, d.args("-k", "graceful")...).CombinedOutput()
	if err != nil {
		return fmt.Errorf("%w: %s", err, out)
	}

	return nil
}

func (d *disposableApache) stop() {
	_ = exec.Command(d.binary, d.args("-k", "stop")...).Run()
}

// freePort binds a loopback listener momentarily to obtain an unused port, then
// releases it before apache binds the same port. A small time-of-check/time-of-
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

// waitForPidFile polls until apache's pid file exists and names a running
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
