// Package agent_test holds the one cross-capability integration test this
// phase adds: proof that a real, disposable nginx instance, configured with
// the new "apache-proxy" web_template, actually proxies a real HTTP request
// through to a real, separate, disposable Apache instance's own real
// content. Neither capability's own per-resource health check can prove
// this by itself (nginx's own apache-proxy health check is deliberately
// generic -- see internal/capability/nginx/capability.go's own comment --
// and Apache's own health check only ever probes itself), so this is the
// one place in the whole test suite that actually exercises both real
// disposable instances together in the same process.
//
// This file lives directly under the module root (not inside either
// capability's own package) because nginx's and apache's own disposable-
// instance harnesses (agent/internal/capability/nginx/harness_test.go and
// agent/internal/capability/apache/harness_test.go) are unexported helpers
// declared in _test.go files: Go does not let a _test.go file's own
// unexported identifiers be imported from a different package, even another
// test package, so this file reads both harnesses for their technique (already
// proven correct by each package's own full test suite) and rebuilds the
// minimal subset it needs directly, importing only the two packages' real,
// exported, non-test APIs (nginx.New/nginx.Config and apache.New/apache.Config).
package agent_test

import (
	"context"
	"crypto/rand"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/apache"
	"github.com/mikho/LESta/agent/internal/capability/nginx"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// TestBothProfileNginxProxiesRealApacheContent is the full "both" profile
// proof: a real disposable Apache instance is given real content for a
// resource via the real ApacheCapability; a real disposable nginx instance
// is then given an "apache-proxy" vhost for the SAME resource_id/domain,
// its ProxyBackend pointed at Apache's own loopback port, via the real
// NginxCapability. A real HTTP request through nginx's own listener, with
// the domain's Host header, must come back carrying Apache's own marker --
// proving nginx really proxied through to Apache's real content, not to
// nginx's own default content or a dead backend.
func TestBothProfileNginxProxiesRealApacheContent(t *testing.T) {
	requireRealNginx(t)
	apacheBinary := requireRealApache(t)

	apacheD := newDisposableApache(t, apacheBinary)
	nginxD := newDisposableNginx(t)

	ctx := context.Background()

	apacheCap := apache.New(apacheD.Config)

	nginxCfg := nginxD.Config
	nginxCfg.ProxyBackend = fmt.Sprintf("127.0.0.1:%d", apacheD.Port)
	nginxCap := nginx.New(nginxCfg)

	resourceID := newTestUUID()
	domain := "both-profile.contract.test"

	apacheResult, err := apacheCap.Apply(ctx, newOp("web.apache.v1", protocol.OperationCreate, resourceID, newTestUUID(), 1, webPayload(domain, "127.0.0.1", "default", false)))
	if err != nil || apacheResult.Status != protocol.StatusApplied {
		t.Fatalf("creating the real apache backend: status=%s err=%v errors=%+v", apacheResult.Status, err, apacheResult.Errors)
	}

	nginxResult, err := nginxCap.Apply(ctx, newOp("web.nginx.v1", protocol.OperationCreate, resourceID, newTestUUID(), 1, webPayload(domain, "127.0.0.1", "apache-proxy", false)))
	if err != nil || nginxResult.Status != protocol.StatusApplied {
		t.Fatalf("creating the nginx proxy vhost: status=%s err=%v errors=%+v", nginxResult.Status, err, nginxResult.Errors)
	}

	body := getViaNginx(t, nginxD.Port, domain)

	expectedMarker := fmt.Sprintf("LESTA-MARKER resource=%s", resourceID)
	if !strings.Contains(body, expectedMarker) {
		t.Fatalf("expected nginx's proxied response to contain apache's own marker %q, got: %q", expectedMarker, body)
	}

	t.Logf("confirmed a real HTTP request through nginx's own listener (port %d) was proxied to and served by a real, separate, disposable Apache instance (port %d): %q", nginxD.Port, apacheD.Port, strings.TrimSpace(body))
}

// --- shared small helpers ---------------------------------------------

func newOp(capability string, operation protocol.Operation, resourceID, idempotencyKey string, desiredStateVersion int, payload map[string]any) protocol.OperationEnvelope {
	raw, err := json.Marshal(payload)
	if err != nil {
		panic(fmt.Sprintf("marshaling test payload: %v", err))
	}

	now := time.Now().UTC()

	return protocol.OperationEnvelope{
		ProtocolVersion:     "1",
		Capability:          capability,
		Operation:           operation,
		ResourceID:          resourceID,
		DesiredStateVersion: desiredStateVersion,
		IdempotencyKey:      idempotencyKey,
		CorrelationID:       newTestUUID(),
		Deadline:            now.Add(10 * time.Second),
		IssuedAt:            now,
		RequestDigest:       "sha256:" + strings.Repeat("0", 64),
		Payload:             raw,
	}
}

func webPayload(domain, ip, webTemplate string, suspended bool) map[string]any {
	return map[string]any{
		"domain":       domain,
		"aliases":      []string{},
		"ip_address":   ip,
		"web_template": webTemplate,
		"ssl":          map[string]any{"mode": "off"},
		"suspended":    suspended,
	}
}

func newTestUUID() string {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		panic(fmt.Sprintf("generating test UUID: %v", err))
	}

	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80

	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16])
}

// freePort binds a loopback listener momentarily to obtain an unused port,
// then releases it before the real process binds the same port. A small
// time-of-check/time-of-use race is inherent to this approach; it is the
// standard, accepted way to pick an ephemeral port for a disposable test
// process (mirroring both capabilities' own harness_test.go).
func freePort(t *testing.T) int {
	t.Helper()

	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("finding a free port: %v", err)
	}
	defer func() { _ = l.Close() }()

	return l.Addr().(*net.TCPAddr).Port
}

// waitForPidFile polls until a pid file exists and names a running process,
// or timeout elapses. Shared by both disposable instances below.
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

// getViaNginx issues a real HTTP GET to nginx's own listener, with the Host
// header set to domain, and returns the response body. Fails the test if the
// request doesn't eventually succeed with 200.
func getViaNginx(t *testing.T, port int, domain string) string {
	t.Helper()

	url := fmt.Sprintf("http://127.0.0.1:%d/", port)

	req, err := http.NewRequest(http.MethodGet, url, nil)
	if err != nil {
		t.Fatalf("building request: %v", err)
	}
	req.Host = domain

	client := &http.Client{Timeout: 5 * time.Second}

	var lastErr error

	for deadline := time.Now().Add(5 * time.Second); time.Now().Before(deadline); {
		resp, err := client.Do(req)
		if err != nil {
			lastErr = err
			time.Sleep(50 * time.Millisecond)

			continue
		}

		body, readErr := io.ReadAll(resp.Body)
		_ = resp.Body.Close()

		if readErr != nil {
			t.Fatalf("reading response body: %v", readErr)
		}

		if resp.StatusCode != http.StatusOK {
			t.Fatalf("expected 200 from %s (Host: %s), got %d: %s", url, domain, resp.StatusCode, body)
		}

		return string(body)
	}

	t.Fatalf("never got a response from %s (Host: %s): %v", url, domain, lastErr)

	return ""
}

// --- disposable nginx (trimmed to what this file's own test needs) ------

// requireRealNginx skips the calling test, with a clear reason, if nginx
// isn't on PATH.
func requireRealNginx(t *testing.T) {
	t.Helper()

	if _, err := exec.LookPath("nginx"); err != nil {
		t.Skip("nginx is not installed on PATH; skipping the both-profile cross-capability test")
	}
}

type disposableNginx struct {
	Config nginx.Config
	Prefix string
	Port   int
}

func newDisposableNginx(t *testing.T) *disposableNginx {
	t.Helper()

	prefix := t.TempDir()
	liveDir := filepath.Join(prefix, "lesta.d")
	stateRoot := filepath.Join(prefix, "state")
	logsDir := filepath.Join(prefix, "logs")

	for _, dir := range []string{liveDir, stateRoot, logsDir} {
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
		Prefix: prefix,
		Port:   port,
		Config: nginx.Config{
			LiveDir:       liveDir,
			StateRoot:     stateRoot,
			NginxConfPath: confPath,
			NginxBinary:   "nginx",
			Prefix:        prefix,
			Port:          port,
		},
	}

	cmd := exec.Command("nginx", "-p", prefix, "-c", confPath)
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("starting disposable nginx: %v: %s", err, out)
	}

	if err := waitForPidFile(pidPath, 5*time.Second); err != nil {
		t.Fatalf("disposable nginx never wrote its pid file: %v", err)
	}

	t.Cleanup(func() {
		_ = exec.Command("nginx", "-p", prefix, "-c", confPath, "-s", "stop").Run()
	})

	return d
}

// --- disposable apache (trimmed to what this file's own test needs) -----
//
// This mirrors agent/internal/capability/apache/harness_test.go's own
// newDisposableApache exactly (same module-detection technique, same
// from-scratch synthetic base config), since that package's own version is
// an unexported test helper this file cannot import. See this file's own
// top comment for why it is rebuilt here instead.

// requireRealApache resolves which real Apache binary is available on PATH,
// trying "apache2" first (the real Ubuntu/Debian package name), falling
// back to "httpd" (this Mac's own build). Skips (never fails) if neither is
// found.
func requireRealApache(t *testing.T) string {
	t.Helper()

	for _, bin := range []string{"apache2", "httpd"} {
		if _, err := exec.LookPath(bin); err == nil {
			return bin
		}
	}

	t.Skip("neither apache2 nor httpd is installed on PATH; skipping the both-profile cross-capability test")

	return ""
}

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

func loadModuleLineIfNeeded(compiledIn map[string]bool, sourceFile, name, path string) string {
	if compiledIn[sourceFile] {
		return ""
	}

	return fmt.Sprintf("LoadModule %s %s\n", name, path)
}

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

type disposableApache struct {
	Config       apache.Config
	Prefix       string
	Port         int
	binary       string
	pidPath      string
	errorLogPath string
}

func newDisposableApache(t *testing.T, binary string) *disposableApache {
	t.Helper()

	moduleDir := resolveModuleDir(t)
	compiledIn := compiledInModules(t, binary)

	prefix := t.TempDir()
	liveDir := filepath.Join(prefix, "lesta.d")
	stateRoot := filepath.Join(prefix, "state")
	logsDir := filepath.Join(prefix, "logs")
	htdocsDir := filepath.Join(prefix, "htdocs")
	acmeChallengeDir := filepath.Join(prefix, "acme-http-01")

	for _, dir := range []string{liveDir, stateRoot, logsDir, htdocsDir, acmeChallengeDir} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatalf("creating %s: %v", dir, err)
		}
	}

	port := freePort(t)
	pidPath := filepath.Join(prefix, "apache.pid")
	confPath := filepath.Join(prefix, "apache2.conf")
	errorLogPath := filepath.Join(logsDir, "error.log")

	mimeTypesPath := filepath.Join(prefix, "mime.types")
	if err := os.WriteFile(mimeTypesPath, []byte("text/plain txt\n"), 0o644); err != nil {
		t.Fatalf("writing minimal mime.types: %v", err)
	}

	var confBuilder strings.Builder

	confBuilder.WriteString(loadModuleLineIfNeeded(compiledIn, "mod_unixd.c", "unixd_module", filepath.Join(moduleDir, "mod_unixd.so")))
	confBuilder.WriteString(resolveMPMModuleLine(t, moduleDir, compiledIn))
	confBuilder.WriteString(loadModuleLineIfNeeded(compiledIn, "mod_authz_core.c", "authz_core_module", filepath.Join(moduleDir, "mod_authz_core.so")))
	confBuilder.WriteString(loadModuleLineIfNeeded(compiledIn, "mod_log_config.c", "log_config_module", filepath.Join(moduleDir, "mod_log_config.so")))
	confBuilder.WriteString(loadModuleLineIfNeeded(compiledIn, "mod_mime.c", "mime_module", filepath.Join(moduleDir, "mod_mime.so")))
	confBuilder.WriteString(loadModuleLineIfNeeded(compiledIn, "mod_dir.c", "dir_module", filepath.Join(moduleDir, "mod_dir.so")))
	confBuilder.WriteString(loadModuleLineIfNeeded(compiledIn, "mod_alias.c", "alias_module", filepath.Join(moduleDir, "mod_alias.so")))
	confBuilder.WriteString(loadModuleLineIfNeeded(compiledIn, "mod_asis.c", "asis_module", filepath.Join(moduleDir, "mod_asis.so")))
	// ssl_module and socache_shmcb_module: ensureModulesFragment (production
	// code, shared with internal/capability/apache) now unconditionally
	// emits its own LoadModule lines for both, pointing at the hardcoded
	// production Ubuntu path. Pre-loading them here, from whichever module
	// directory actually exists on the machine running this test, makes
	// that a harmless, silently-skipped duplicate-by-name -- exactly the
	// same fix apache/harness_test.go's own newDisposableApache applies for
	// its own suite (see that file's own comment for the verified
	// precedent this relies on).
	confBuilder.WriteString(loadModuleLineIfNeeded(compiledIn, "mod_ssl.c", "ssl_module", filepath.Join(moduleDir, "mod_ssl.so")))
	confBuilder.WriteString(loadModuleLineIfNeeded(compiledIn, "mod_socache_shmcb.c", "socache_shmcb_module", filepath.Join(moduleDir, "mod_socache_shmcb.so")))
	fmt.Fprintf(&confBuilder, "TypesConfig %s\n", mimeTypesPath)
	fmt.Fprintf(&confBuilder, "PidFile %s\n", pidPath)
	fmt.Fprintf(&confBuilder, "Listen 127.0.0.1:%d\n", port)
	fmt.Fprintf(&confBuilder, "ErrorLog %s\n", errorLogPath)
	fmt.Fprintf(&confBuilder, "DocumentRoot %s\n", htdocsDir)
	fmt.Fprintf(&confBuilder, "IncludeOptional %s\n", filepath.Join(liveDir, "*.conf"))

	if err := os.WriteFile(confPath, []byte(confBuilder.String()), 0o644); err != nil {
		t.Fatalf("writing disposable apache2.conf: %v", err)
	}

	d := &disposableApache{
		Prefix:       prefix,
		Port:         port,
		binary:       binary,
		pidPath:      pidPath,
		errorLogPath: errorLogPath,
		Config: apache.Config{
			LiveDir:          liveDir,
			StateRoot:        stateRoot,
			ApacheConfPath:   confPath,
			ApacheBinary:     binary,
			Prefix:           prefix,
			Port:             port,
			AcmeChallengeDir: acmeChallengeDir,
			// SSLPort intentionally left 0: this test never exercises SSL,
			// and the "both" profile's own real production wiring also
			// keeps Apache's SSLPort at 0 (see main.go's
			// apacheSSLPortForProfile), so 0 is the faithful default here
			// too.
		},
	}

	cmd := exec.Command(binary, "-d", prefix, "-f", confPath, "-k", "start")
	if out, err := cmd.CombinedOutput(); err != nil {
		errorLog, _ := os.ReadFile(errorLogPath)
		t.Fatalf("starting disposable apache: %v: stderr/stdout=%q error_log=%q", err, out, errorLog)
	}

	if err := waitForPidFile(pidPath, 5*time.Second); err != nil {
		t.Fatalf("disposable apache never wrote its pid file: %v", err)
	}

	t.Cleanup(func() {
		_ = exec.Command(binary, "-d", prefix, "-f", confPath, "-k", "stop").Run()
	})

	return d
}
