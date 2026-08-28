package bind9_test

import (
	"crypto/rand"
	"encoding/base64"
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

	"github.com/mikho/LESta/agent/internal/capability/bind9"
)

// requireRealBind9 skips the calling test, with a clear reason, if named,
// named-checkconf, or rndc aren't all on PATH. No build tag: `go test ./...`
// runs everything not requiring BIND9 unconditionally, and only the
// real-capability suite self-skips here.
func requireRealBind9(t *testing.T) {
	t.Helper()

	for _, bin := range []string{"named", "named-checkconf", "rndc"} {
		if _, err := exec.LookPath(bin); err != nil {
			t.Skipf("%s is not installed on PATH; skipping the real Bind9Capability contract suite", bin)
		}
	}
}

// disposableBind9 is a fully disposable, per-test named process: its own
// named.conf and rndc.conf under t.TempDir(), listening on ephemeral loopback
// ports for both DNS and the rndc control channel. Zero sudo, zero systemd,
// identical on this Mac, a Multipass VM, or a GitHub Actions Ubuntu runner.
type disposableBind9 struct {
	Config    bind9.Config
	Prefix    string
	Port      int
	rndcPort  int
	namedConf string
	rndcConf  string
	pidPath   string
}

// newDisposableBind9 starts a fresh named process and registers its teardown
// via t.Cleanup. The returned Config is ready to hand to bind9.New.
func newDisposableBind9(t *testing.T) *disposableBind9 {
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

	// named's own glob include fails outright if it matches zero files (see
	// validate.go's ensurePlaceholderFragment doc comment), unlike nginx's
	// equivalent; a real bootstrap installer would need to seed this before
	// named's first-ever start. This test harness stands in for that
	// bootstrap step, exactly as a production install.sh would need to.
	if err := os.WriteFile(filepath.Join(liveDir, "_lesta-placeholder.conf"), []byte("# placeholder\n"), 0o644); err != nil {
		t.Fatalf("seeding placeholder fragment: %v", err)
	}

	dnsPort := freePort(t)
	rndcPort := freePort(t)

	pidPath := filepath.Join(prefix, "named.pid")
	namedConfPath := filepath.Join(prefix, "named.conf")
	rndcConfPath := filepath.Join(prefix, "rndc.conf")

	secretBytes := make([]byte, 32)
	if _, err := rand.Read(secretBytes); err != nil {
		t.Fatalf("generating rndc key secret: %v", err)
	}
	secret := base64.StdEncoding.EncodeToString(secretBytes)

	namedConfBody := fmt.Sprintf(`options {
    directory "%s";
    pid-file "%s";
    listen-on port %d { 127.0.0.1; };
    listen-on-v6 { none; };
    recursion no;
    dnssec-validation no;
    allow-transfer { none; };
};

logging {
    channel default_log {
        file "%s";
        severity info;
        print-time yes;
    };
    category default { default_log; };
};

controls {
    inet 127.0.0.1 port %d allow { 127.0.0.1; } keys { "rndc-key"; };
};

key "rndc-key" {
    algorithm hmac-sha256;
    secret "%s";
};

include "%s";
`,
		prefix,
		pidPath,
		dnsPort,
		filepath.Join(logsDir, "named.log"),
		rndcPort,
		secret,
		filepath.Join(liveDir, "*.conf"),
	)

	if err := os.WriteFile(namedConfPath, []byte(namedConfBody), 0o644); err != nil {
		t.Fatalf("writing disposable named.conf: %v", err)
	}

	rndcConfBody := fmt.Sprintf(`options {
    default-server 127.0.0.1;
    default-port %d;
    default-key "rndc-key";
};

key "rndc-key" {
    algorithm hmac-sha256;
    secret "%s";
};
`,
		rndcPort,
		secret,
	)

	if err := os.WriteFile(rndcConfPath, []byte(rndcConfBody), 0o644); err != nil {
		t.Fatalf("writing disposable rndc.conf: %v", err)
	}

	d := &disposableBind9{
		Prefix:    prefix,
		Port:      dnsPort,
		rndcPort:  rndcPort,
		namedConf: namedConfPath,
		rndcConf:  rndcConfPath,
		pidPath:   pidPath,
		Config: bind9.Config{
			LiveDir:              liveDir,
			StateRoot:            stateRoot,
			NamedConfPath:        namedConfPath,
			NamedCheckconfBinary: "named-checkconf",
			RndcBinary:           "rndc",
			RndcConfigPath:       rndcConfPath,
			ListenAddress:        "127.0.0.1",
			Port:                 dnsPort,
			Nameservers:          []string{"ns1.lesta-hosting.test.", "ns2.lesta-hosting.test."},
		},
	}

	d.start(t)

	t.Cleanup(func() { d.stop() })

	return d
}

func (d *disposableBind9) start(t *testing.T) {
	t.Helper()

	cmd := exec.Command("named", "-c", d.namedConf)
	out, err := cmd.CombinedOutput()
	if err != nil {
		t.Fatalf("starting disposable named: %v: %s", err, out)
	}

	if err := waitForPidFile(d.pidPath, 5*time.Second); err != nil {
		t.Fatalf("disposable named never wrote its pid file: %v", err)
	}
}

// reload issues a real `rndc reload` against this disposable instance
// directly (bypassing Bind9Capability), used by tests that need to confirm
// the instance's own state independent of the capability under test.
func (d *disposableBind9) reload() error {
	out, err := exec.Command("rndc", "-c", d.rndcConf, "reload").CombinedOutput()
	if err != nil {
		return fmt.Errorf("%w: %s", err, out)
	}

	return nil
}

func (d *disposableBind9) stop() {
	_ = exec.Command("rndc", "-c", d.rndcConf, "stop").Run()
}

// freePort binds a loopback listener momentarily to obtain an unused port,
// then releases it before named binds the same port. A small time-of-check/
// time-of-use race is inherent to this approach; it is the standard, accepted
// way to pick an ephemeral port for a disposable test process.
func freePort(t *testing.T) int {
	t.Helper()

	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("finding a free port: %v", err)
	}
	defer l.Close()

	return l.Addr().(*net.TCPAddr).Port
}

// waitForPidFile polls until named's pid file exists and names a running
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
