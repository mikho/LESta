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

// compiledInModules runs `<binary> -l` (List compiled-in modules) and returns
// the set of source file names (e.g. "mod_unixd.c") it reports. Found via a
// real CI failure, not anticipated in advance: Ubuntu's real apache2 package
// compiles mod_unixd statically into the binary, and an explicit
// `LoadModule unixd_module ...` against such a binary is a hard startup
// error ("module unixd_module is built-in and can't be loaded"), unlike
// loading an already-*loaded* dynamic module by the same name (which
// production's own ensureModulesFragment relies on being a harmless no-op).
// This Mac's own httpd build compiles in only core.c/mod_so.c/http_core.c
// (confirmed via `httpd -l`), so a fixed, unconditional module list that
// works here silently breaks on Ubuntu; checking `-l` directly is what makes
// the identical harness code work on both.
func compiledInModules(t *testing.T, binary string) map[string]bool {
	t.Helper()

	out, err := exec.Command(binary, "-l").CombinedOutput()
	if err != nil {
		t.Fatalf("listing %s's compiled-in modules: %v: %s", binary, err, out)
	}

	compiled := make(map[string]bool)

	for _, line := range strings.Split(string(out), "\n") {
		if line = strings.TrimSpace(line); strings.HasSuffix(line, ".c") {
			compiled[line] = true
		}
	}

	return compiled
}

// loadModuleLineIfNeeded returns a "LoadModule name path\n" line, or "" if
// compiledIn already reports sourceFile built into the binary (see
// compiledInModules).
func loadModuleLineIfNeeded(compiledIn map[string]bool, sourceFile, name, path string) string {
	if compiledIn[sourceFile] {
		return ""
	}

	return fmt.Sprintf("LoadModule %s %s\n", name, path)
}

// resolveMPMModuleLine returns the LoadModule line for whichever MPM module
// is available, or "" if the binary already compiles one in statically.
// Prefers event over prefork when both are loadable (Ubuntu's apache2
// package ships mpm_event; this Mac's own build, verified hands-on, also
// ships mpm_event), falling back to prefork only if event is neither
// compiled in nor has a loadable .so in moduleDir.
func resolveMPMModuleLine(t *testing.T, moduleDir string, compiledIn map[string]bool) string {
	t.Helper()

	for _, m := range [...]struct{ source, name, file string }{
		{"mod_mpm_event.c", "mpm_event_module", "mod_mpm_event.so"},
		{"mod_mpm_prefork.c", "mpm_prefork_module", "mod_mpm_prefork.so"},
	} {
		if compiledIn[m.source] {
			return ""
		}

		path := filepath.Join(moduleDir, m.file)
		if _, err := os.Stat(path); err == nil {
			return fmt.Sprintf("LoadModule %s %s\n", m.name, path)
		}
	}

	t.Fatalf("no usable MPM module found (checked compiled-in modules and %s for mod_mpm_event.so/mod_mpm_prefork.so)", moduleDir)

	return ""
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
	compiledIn := compiledInModules(t, binary)

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

	var confBuilder strings.Builder

	confBuilder.WriteString(loadModuleLineIfNeeded(compiledIn, "mod_unixd.c", "unixd_module", filepath.Join(moduleDir, "mod_unixd.so")))
	confBuilder.WriteString(resolveMPMModuleLine(t, moduleDir, compiledIn))
	confBuilder.WriteString(loadModuleLineIfNeeded(compiledIn, "mod_authz_core.c", "authz_core_module", filepath.Join(moduleDir, "mod_authz_core.so")))
	confBuilder.WriteString(loadModuleLineIfNeeded(compiledIn, "mod_log_config.c", "log_config_module", filepath.Join(moduleDir, "mod_log_config.so")))
	confBuilder.WriteString(loadModuleLineIfNeeded(compiledIn, "mod_mime.c", "mime_module", filepath.Join(moduleDir, "mod_mime.so")))
	confBuilder.WriteString(loadModuleLineIfNeeded(compiledIn, "mod_dir.c", "dir_module", filepath.Join(moduleDir, "mod_dir.so")))
	confBuilder.WriteString(loadModuleLineIfNeeded(compiledIn, "mod_asis.c", "asis_module", filepath.Join(moduleDir, "mod_asis.so")))
	fmt.Fprintf(&confBuilder, "PidFile %s\n", pidPath)
	fmt.Fprintf(&confBuilder, "Listen 127.0.0.1:%d\n", port)
	fmt.Fprintf(&confBuilder, "ErrorLog %s\n", filepath.Join(logsDir, "error.log"))
	fmt.Fprintf(&confBuilder, "DocumentRoot %s\n", htdocsDir)
	fmt.Fprintf(&confBuilder, "IncludeOptional %s\n", filepath.Join(liveDir, "*.conf"))

	if err := os.WriteFile(confPath, []byte(confBuilder.String()), 0o644); err != nil {
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
