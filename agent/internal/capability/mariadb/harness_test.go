package mariadb_test

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
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

	"github.com/mikho/LESta/agent/internal/capability/mariadb"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// requireRealMariaDB skips the calling test, with a clear reason, if
// mariadbd, mariadb-install-db, or mariadb aren't all on PATH. No build tag:
// `go test ./...` runs everything not requiring a real MariaDB
// unconditionally, and only this real-capability contract suite self-skips
// here, mirroring bind9's own requireRealBind9.
func requireRealMariaDB(t *testing.T) {
	t.Helper()

	for _, bin := range []string{"mariadbd", "mariadb-install-db", "mariadb"} {
		if _, err := exec.LookPath(bin); err != nil {
			t.Skipf("%s is not installed on PATH; skipping the real MariaDBCapability contract suite", bin)
		}
	}
}

// disposableMariaDB is a fully disposable, per-test tenant mariadbd
// instance: its own datadir under t.TempDir(), listening on an ephemeral
// loopback TCP port, with a dedicated admin account created the same way a
// real install.sh would (never connecting as bare root over TCP), whose
// credentials are written to a real --defaults-extra-file ini exactly as
// production does. Zero sudo, zero systemd, identical on this machine, a
// disposable CI runner, or a real Ubuntu box.
type disposableMariaDB struct {
	Config  mariadb.Config
	prefix  string
	port    int
	socket  string
	pidPath string
}

// newDisposableMariaDB starts a fresh mariadbd process and registers its
// teardown via t.Cleanup. The returned *disposableMariaDB's Config is ready
// to hand to mariadb.New.
func newDisposableMariaDB(t *testing.T) *disposableMariaDB {
	t.Helper()

	prefix := t.TempDir()
	dataDir := filepath.Join(prefix, "data")
	stateRoot := filepath.Join(prefix, "state")

	for _, dir := range []string{dataDir, stateRoot} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatalf("creating %s: %v", dir, err)
		}
	}

	// The Unix socket path has a hard ~103-byte kernel limit, well under
	// what t.TempDir() produces on macOS (a long, per-test-name $TMPDIR
	// subdirectory) -- confirmed directly against this exact failure
	// ("The socket file path is too long (> 103)") running this suite
	// locally. shortSocketPath sidesteps it with a short, unique path
	// directly under /tmp, independent of t.TempDir()'s own naming.
	socket := shortSocketPath(t)
	pidPath := filepath.Join(prefix, "mariadbd.pid")
	errorLog := filepath.Join(prefix, "error.log")
	port := freePort(t)

	if out, err := exec.Command("mariadb-install-db",
		"--datadir="+dataDir,
		"--auth-root-authentication-method=normal",
		"--skip-test-db",
	).CombinedOutput(); err != nil {
		t.Fatalf("mariadb-install-db: %v: %s", err, out)
	}

	cmd := exec.Command("mariadbd",
		"--no-defaults",
		"--datadir="+dataDir,
		"--socket="+socket,
		"--port="+strconv.Itoa(port),
		"--bind-address=127.0.0.1",
		"--pid-file="+pidPath,
		"--skip-networking=0",
		"--log-error="+errorLog,
	)
	if err := cmd.Start(); err != nil {
		t.Fatalf("starting disposable mariadbd: %v", err)
	}

	d := &disposableMariaDB{prefix: prefix, port: port, socket: socket, pidPath: pidPath}

	if err := d.waitUntilReady(30 * time.Second); err != nil {
		out, _ := os.ReadFile(errorLog)
		t.Fatalf("disposable mariadbd never became ready: %v\nerror log:\n%s", err, out)
	}

	adminPassword := randomHex(t, 24)
	createAdmin := fmt.Sprintf(
		"CREATE USER 'lesta_test_admin'@'127.0.0.1' IDENTIFIED BY '%s';\n"+
			"GRANT ALL PRIVILEGES ON *.* TO 'lesta_test_admin'@'127.0.0.1' WITH GRANT OPTION;\n"+
			"FLUSH PRIVILEGES;\n",
		adminPassword,
	)

	rootCmd := exec.Command("mariadb", "--socket="+socket, "-u", "root")
	rootCmd.Stdin = strings.NewReader(createAdmin)
	if out, err := rootCmd.CombinedOutput(); err != nil {
		t.Fatalf("creating disposable admin user: %v: %s", err, out)
	}

	defaultsFile := filepath.Join(prefix, "admin.cnf")
	defaultsContent := fmt.Sprintf("[client]\nuser=lesta_test_admin\npassword=%s\n", adminPassword)
	if err := os.WriteFile(defaultsFile, []byte(defaultsContent), 0o600); err != nil {
		t.Fatalf("writing disposable defaults-extra-file: %v", err)
	}

	d.Config = mariadb.Config{
		Host:              "127.0.0.1",
		Port:              port,
		MariaDBBinary:     "mariadb",
		DefaultsExtraFile: defaultsFile,
		StateRoot:         stateRoot,
	}

	t.Cleanup(func() { d.stop() })

	return d
}

// waitUntilReady polls a real `SELECT 1` round-trip over the Unix socket
// (root, no password: --auth-root-authentication-method=normal's own
// documented behavior) until it succeeds or timeout elapses. A real
// connection attempt is a stronger readiness signal than merely checking
// the pid file exists: mariadbd's InnoDB initialization can take a moment
// after the process itself starts.
func (d *disposableMariaDB) waitUntilReady(timeout time.Duration) error {
	deadline := time.Now().Add(timeout)

	var lastErr error

	for time.Now().Before(deadline) {
		out, err := exec.Command("mariadb", "--socket="+d.socket, "-u", "root", "-e", "SELECT 1;").CombinedOutput()
		if err == nil {
			return nil
		}

		lastErr = fmt.Errorf("%w: %s", err, out)
		time.Sleep(100 * time.Millisecond)
	}

	return fmt.Errorf("mariadbd at socket %s never answered SELECT 1 within %s (last error: %v)", d.socket, timeout, lastErr)
}

// stop issues a real SQL SHUTDOWN over the admin connection, then waits for
// the process to actually exit before returning. SHUTDOWN itself returns as
// soon as the server has begun shutting down, not once it has actually
// released every file it holds open inside this test's own t.TempDir() (its
// data files, socket, and pid file). Since Go's t.Cleanup callbacks run in
// LIFO order, t.TempDir()'s own RemoveAll cleanup (registered before this
// one) runs right after this function returns; without waiting here, that
// RemoveAll can race a still-shutting-down mariadbd and fail with "directory
// not empty" -- the identical hard-won lesson bind9's own harness_test.go
// documents for `named`/`rndc stop`.
func (d *disposableMariaDB) stop() {
	pid := readPid(d.pidPath)

	_, _ = exec.Command("mariadb",
		"--defaults-extra-file="+filepath.Join(d.prefix, "admin.cnf"),
		"--host=127.0.0.1", "--port="+strconv.Itoa(d.port), "--protocol=TCP",
		"-e", "SHUTDOWN;",
	).CombinedOutput()

	if pid > 0 {
		waitForProcessExit(pid, 10*time.Second)
	}
}

// adminSQL runs script directly against this disposable instance's own
// admin connection, bypassing MariaDBCapability entirely -- used by tests
// that need to establish or verify state independent of the capability
// under test (e.g. an out-of-band manual REVOKE to prove observe's own
// drift detection).
func (d *disposableMariaDB) adminSQL(script string) (string, error) {
	cmd := exec.Command("mariadb",
		"--defaults-extra-file="+filepath.Join(d.prefix, "admin.cnf"),
		"--host=127.0.0.1", "--port="+strconv.Itoa(d.port), "--protocol=TCP",
		"--batch", "--skip-column-names",
	)
	cmd.Stdin = strings.NewReader(script)

	out, err := cmd.CombinedOutput()
	if err != nil {
		return "", fmt.Errorf("%w: %s", err, out)
	}

	return string(out), nil
}

// connectAsTenant runs sql as databaseUser/password against database over a
// real TCP connection, bypassing MariaDBCapability entirely -- this is what
// proves create/suspend/unsuspend/rotate/delete actually took effect against
// a real client connection, not merely that MariaDBCapability.Apply returned
// StatusApplied.
func (d *disposableMariaDB) connectAsTenant(databaseUser, password, database, sql string) (string, error) {
	out, err := exec.Command("mariadb",
		"--host=127.0.0.1", "--port="+strconv.Itoa(d.port), "--protocol=TCP",
		"-u", databaseUser, "--password="+password,
		"--batch", "--skip-column-names",
		database, "-e", sql,
	).CombinedOutput()
	if err != nil {
		return "", fmt.Errorf("%w: %s", err, out)
	}

	return string(out), nil
}

// randomHex returns n random bytes hex-encoded, matching CreateTenantDatabase/
// RotateTenantDatabasePassword's own bin2hex(random_bytes(...)) shape (though
// this one is only ever used for this disposable instance's own throwaway
// admin password, never asserted against passwordPattern).
func randomHex(t *testing.T, n int) string {
	t.Helper()

	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		t.Fatalf("generating random hex: %v", err)
	}

	return hex.EncodeToString(b)
}

// newTestUUID returns a fresh random UUID for OperationEnvelope's own
// resource_id/idempotency_key/correlation_id fields, mirroring acme's/
// bind9's own harness_test.go helper of the same name and shape.
func newTestUUID() string {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		panic(fmt.Sprintf("generating test UUID: %v", err))
	}

	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80

	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16])
}

func newOp(operation protocol.Operation, resourceID, idempotencyKey string, desiredStateVersion int, payload map[string]any) protocol.OperationEnvelope {
	raw, err := json.Marshal(payload)
	if err != nil {
		panic(fmt.Sprintf("marshaling test payload: %v", err))
	}

	now := time.Now().UTC()

	return protocol.OperationEnvelope{
		ProtocolVersion:     "1",
		Capability:          "database.tenant.v1",
		Operation:           operation,
		ResourceID:          resourceID,
		DesiredStateVersion: desiredStateVersion,
		IdempotencyKey:      idempotencyKey,
		CorrelationID:       newTestUUID(),
		Deadline:            now.Add(30 * time.Second),
		IssuedAt:            now,
		RequestDigest:       "sha256:" + strings.Repeat("0", 64),
		Payload:             raw,
	}
}

// tenantPayload builds the fixed database.tenant.v1 payload shape.
// password/suspended are the only fields that vary per call site.
func tenantPayload(databaseName, databaseUser string, password *string, suspended bool) map[string]any {
	payload := map[string]any{
		"database_name": databaseName,
		"database_user": databaseUser,
		"suspended":     suspended,
	}

	if password != nil {
		payload["password"] = *password
	}

	return payload
}

func strPtr(s string) *string { return &s }

func requireStatus(t *testing.T, label string, result protocol.ResultEnvelope, err error, want protocol.Status) {
	t.Helper()

	if err != nil {
		t.Fatalf("%s: Apply returned an error (no verdict reached): %v", label, err)
	}
	if result.Status != want {
		t.Fatalf("%s: expected status %s, got %s (errors=%+v)", label, want, result.Status, result.Errors)
	}
}

func requireApplied(t *testing.T, label string, result protocol.ResultEnvelope, err error) {
	t.Helper()

	requireStatus(t, label, result, err, protocol.StatusApplied)
}

func requireErrorCode(t *testing.T, label string, result protocol.ResultEnvelope, code string) {
	t.Helper()

	for _, e := range result.Errors {
		if e.Code == code {
			return
		}
	}

	t.Fatalf("%s: expected an error with code %q, got %+v", label, code, result.Errors)
}

// shortSocketPath allocates a short, unique path directly under /tmp for
// mariadbd's own Unix socket. Never t.TempDir(): on macOS that resolves
// under a long, per-test-name $TMPDIR subdirectory, which reliably exceeds
// the kernel's ~103-byte sockaddr_un path limit for a test name of any
// realistic length, causing mariadbd to abort at startup with "The socket
// file path is too long" -- confirmed directly running this suite locally,
// not a hypothetical.
func shortSocketPath(t *testing.T) string {
	t.Helper()

	f, err := os.CreateTemp("/tmp", "lesta-mdb-*.sock")
	if err != nil {
		t.Fatalf("allocating a short socket path: %v", err)
	}

	path := f.Name()
	_ = f.Close()
	_ = os.Remove(path) // mariadbd itself creates this path as a socket, not a regular file

	t.Cleanup(func() { _ = os.Remove(path) })

	return path
}

// freePort binds a loopback listener momentarily to obtain an unused port,
// then releases it before mariadbd binds the same port. A small time-of-
// check/time-of-use race is inherent to this approach; it is the standard,
// accepted way to pick an ephemeral port for a disposable test process
// (mirroring bind9's own harness_test.go helper of the same name).
func freePort(t *testing.T) int {
	t.Helper()

	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("finding a free port: %v", err)
	}
	defer l.Close()

	return l.Addr().(*net.TCPAddr).Port
}

// readPid reads and parses pidPath's content, returning 0 if it is missing
// or unparseable (best-effort: this only feeds stop()'s own post-shutdown
// wait, never a hard failure).
func readPid(pidPath string) int {
	raw, err := os.ReadFile(pidPath)
	if err != nil {
		return 0
	}

	pid, err := strconv.Atoi(strings.TrimSpace(string(raw)))
	if err != nil {
		return 0
	}

	return pid
}

// waitForProcessExit polls until pid is no longer a running process, or
// timeout elapses. Best-effort: this is cleanup, not a test assertion, so it
// never fails the test itself, even on timeout.
func waitForProcessExit(pid int, timeout time.Duration) {
	deadline := time.Now().Add(timeout)

	for time.Now().Before(deadline) {
		proc, err := os.FindProcess(pid)
		if err != nil {
			return
		}

		if err := proc.Signal(syscall.Signal(0)); err != nil {
			return
		}

		time.Sleep(50 * time.Millisecond)
	}
}
