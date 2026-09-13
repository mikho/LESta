package metrics_test

import (
	"context"
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

	"github.com/mikho/LESta/agent/internal/capability/metrics"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// requireRealMariaDB skips the calling test, with a clear reason, if
// mariadbd, mariadb-install-db, or mariadb aren't all on PATH, mirroring
// internal/capability/mariadb's own harness_test.go exactly.
func requireRealMariaDB(t *testing.T) {
	t.Helper()

	for _, bin := range []string{"mariadbd", "mariadb-install-db", "mariadb"} {
		if _, err := exec.LookPath(bin); err != nil {
			t.Skipf("%s is not installed on PATH; skipping the real databaseDiskBytes contract suite", bin)
		}
	}
}

// disposableMariaDB is a fully disposable, per-test mariadbd instance,
// mirroring internal/capability/mariadb's own harness_test.go almost
// verbatim (that package cannot be imported here: its own helper is
// unexported and this suite needs its own real instance regardless, to
// prove metrics.usage.v1's own databaseDiskBytes -- a completely different
// code path, connecting as a read-only stats account rather than the
// tenant-admin account mariadb.Capability itself uses).
type disposableMariaDB struct {
	port    int
	socket  string
	prefix  string
	pidPath string
}

func newDisposableMariaDB(t *testing.T) *disposableMariaDB {
	t.Helper()

	prefix := t.TempDir()
	dataDir := filepath.Join(prefix, "data")
	if err := os.MkdirAll(dataDir, 0o755); err != nil {
		t.Fatalf("creating %s: %v", dataDir, err)
	}

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

	t.Cleanup(func() { d.stop() })

	return d
}

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

func (d *disposableMariaDB) stop() {
	pid := readPid(d.pidPath)

	_, _ = exec.Command("mariadb", "--socket="+d.socket, "-u", "root", "-e", "SHUTDOWN;").CombinedOutput()

	if pid > 0 {
		waitForProcessExit(pid, 10*time.Second)
	}
}

// rootSQL runs script as root over the disposable instance's own Unix
// socket, bypassing metrics.usage.v1 entirely -- used to set up a real
// database, real data, and a real least-privilege stats account before the
// capability under test ever runs.
func (d *disposableMariaDB) rootSQL(t *testing.T, script string) {
	t.Helper()

	cmd := exec.Command("mariadb", "--socket="+d.socket, "-u", "root")
	cmd.Stdin = strings.NewReader(script)

	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("root SQL setup failed: %v: %s", err, out)
	}
}

func shortSocketPath(t *testing.T) string {
	t.Helper()

	f, err := os.CreateTemp("/tmp", "lesta-metrics-mdb-*.sock")
	if err != nil {
		t.Fatalf("allocating a short socket path: %v", err)
	}

	path := f.Name()
	_ = f.Close()
	_ = os.Remove(path)

	t.Cleanup(func() { _ = os.Remove(path) })

	return path
}

func freePort(t *testing.T) int {
	t.Helper()

	l, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatalf("finding a free port: %v", err)
	}
	defer l.Close()

	return l.Addr().(*net.TCPAddr).Port
}

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

func TestDatabaseDiskBytesReportsARealNonZeroSizeAgainstARealTable(t *testing.T) {
	requireRealMariaDB(t)

	d := newDisposableMariaDB(t)

	const (
		databaseName  = "lesta_1_metricstest"
		statsUser     = "lesta_1_metricstest_ro"
		statsPassword = "dfb61ef6330ac7f4d327de7aaadb0abbcd6a1baaa8480ec9"
	)

	d.rootSQL(t, fmt.Sprintf(
		"CREATE DATABASE `%s`;\n"+
			"CREATE TABLE `%s`.`widgets` (id INT PRIMARY KEY, payload VARCHAR(4000));\n"+
			"INSERT INTO `%s`.`widgets` (id, payload) VALUES (1, REPEAT('x', 4000)), (2, REPEAT('y', 4000));\n"+
			"CREATE USER '%s'@'127.0.0.1' IDENTIFIED BY '%s';\n"+
			"GRANT SELECT, SHOW VIEW ON `%s`.* TO '%s'@'127.0.0.1';\n"+
			"FLUSH PRIVILEGES;\n",
		databaseName, databaseName, databaseName, statsUser, statsPassword, databaseName, statsUser,
	))

	capability := metrics.New(metrics.Config{
		MariaDBHost:   "127.0.0.1",
		MariaDBPort:   d.port,
		MariaDBBinary: "mariadb",
	})

	resourceID := newTestUUID()

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, map[string]any{
		"tenant_databases": []map[string]any{{
			"resource_uuid":  resourceID,
			"database_name":  databaseName,
			"stats_user":     statsUser,
			"stats_password": statsPassword,
		}},
	}))
	requireStatus(t, "real database size query", result, err, protocol.StatusApplied)

	var data struct {
		TenantDatabases []struct {
			ResourceUUID string `json:"resource_uuid"`
			DiskBytes    int64  `json:"disk_bytes"`
		} `json:"tenant_databases"`
	}
	if unmarshalErr := json.Unmarshal(result.Data, &data); unmarshalErr != nil {
		t.Fatalf("decoding Data: %v", unmarshalErr)
	}

	if len(data.TenantDatabases) != 1 || data.TenantDatabases[0].ResourceUUID != resourceID {
		t.Fatalf("expected exactly one tenant database usage entry for %s, got %+v", resourceID, data.TenantDatabases)
	}

	// InnoDB's own per-table overhead means this is never exactly the raw
	// payload byte count; a real, non-trivial size (well above a bare empty
	// table's own few-KB minimum) is the genuine proof this queried real
	// InnoDB metadata, not a stub.
	if data.TenantDatabases[0].DiskBytes < 16*1024 {
		t.Fatalf("expected a real InnoDB size of at least 16KB for two 4000-byte rows, got %d", data.TenantDatabases[0].DiskBytes)
	}
}

func TestDatabaseDiskBytesRejectsAWrongStatsPassword(t *testing.T) {
	requireRealMariaDB(t)

	d := newDisposableMariaDB(t)

	const (
		databaseName = "lesta_1_metricsauth"
		statsUser    = "lesta_1_metricsauth_ro"
	)

	const realPassword = "f963c0c0d0ae2dbce6f2414d484147e453df8eee37be5f39"

	d.rootSQL(t, fmt.Sprintf(
		"CREATE DATABASE `%s`;\n"+
			"CREATE USER '%s'@'127.0.0.1' IDENTIFIED BY '%s';\n"+
			"GRANT SELECT, SHOW VIEW ON `%s`.* TO '%s'@'127.0.0.1';\n"+
			"FLUSH PRIVILEGES;\n",
		databaseName, statsUser, realPassword, databaseName, statsUser,
	))

	capability := metrics.New(metrics.Config{
		MariaDBHost:   "127.0.0.1",
		MariaDBPort:   d.port,
		MariaDBBinary: "mariadb",
	})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, map[string]any{
		"tenant_databases": []map[string]any{{
			"resource_uuid":  newTestUUID(),
			"database_name":  databaseName,
			"stats_user":     statsUser,
			"stats_password": "7c06fbac74fcdad4c725762272acf893850598eaefab5666",
		}},
	}))
	requireStatus(t, "wrong stats password", result, err, protocol.StatusDegraded)
	requireErrorCode(t, "wrong stats password", result, "database_measurement_failed")
}
