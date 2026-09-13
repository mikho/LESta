package backup_test

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/backup"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// requireRealMariaDBForDump skips the calling test, with a clear reason, if
// mariadbd, mariadb-install-db, mariadb, or a real dump binary (mariadb-dump
// or mysqldump) aren't all on PATH. Mirrors the mariadb package's own
// requireRealMariaDB exactly, plus the dump-binary check this package's own
// mariadbDumpBinary() needs.
func requireRealMariaDBForDump(t *testing.T) {
	t.Helper()

	for _, bin := range []string{"mariadbd", "mariadb-install-db", "mariadb"} {
		if _, err := exec.LookPath(bin); err != nil {
			t.Skipf("%s is not installed on PATH; skipping the real database-dump backup suite", bin)
		}
	}

	if _, err := exec.LookPath("mariadb-dump"); err != nil {
		if _, err := exec.LookPath("mysqldump"); err != nil {
			t.Skip("neither mariadb-dump nor mysqldump is installed on PATH; skipping the real database-dump backup suite")
		}
	}
}

// disposableDumpMariaDB is a minimal, fully disposable mariadbd instance
// exposing only a real Unix socket (no TCP needed: dumpSocket only ever
// connects via socket, root-authenticated, exactly like this project's own
// installer health checks), just enough to prove a real mysqldump/
// mariadb-dump round trip against backup.encrypted-artifacts.v1's own Apply.
type disposableDumpMariaDB struct {
	socket  string
	prefix  string
	pidPath string
}

func newDisposableDumpMariaDB(t *testing.T) *disposableDumpMariaDB {
	t.Helper()

	prefix := t.TempDir()
	dataDir := filepath.Join(prefix, "data")
	if err := os.MkdirAll(dataDir, 0o755); err != nil {
		t.Fatalf("creating data dir: %v", err)
	}

	socket := shortDumpSocketPath(t)
	pidPath := filepath.Join(prefix, "mariadbd.pid")
	errorLog := filepath.Join(prefix, "error.log")

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
		"--skip-networking",
		"--pid-file="+pidPath,
		"--log-error="+errorLog,
	)
	if err := cmd.Start(); err != nil {
		t.Fatalf("starting disposable mariadbd: %v", err)
	}

	d := &disposableDumpMariaDB{socket: socket, prefix: prefix, pidPath: pidPath}

	if err := d.waitUntilReady(30 * time.Second); err != nil {
		out, _ := os.ReadFile(errorLog)
		t.Fatalf("disposable mariadbd never became ready: %v\nerror log:\n%s", err, out)
	}

	t.Cleanup(func() { d.stop() })

	return d
}

func (d *disposableDumpMariaDB) waitUntilReady(timeout time.Duration) error {
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

// seedRealData creates a real schema, table, and row directly against this
// disposable instance's own root socket connection, bypassing
// BackupCapability entirely -- this is the real content a dump must
// actually round-trip, not something the capability under test could have
// fabricated itself.
func (d *disposableDumpMariaDB) seedRealData(t *testing.T, schema, marker string) {
	t.Helper()

	script := fmt.Sprintf(
		"CREATE DATABASE %s;\n"+
			"CREATE TABLE %s.probe (id INT PRIMARY KEY, value VARCHAR(255));\n"+
			"INSERT INTO %s.probe (id, value) VALUES (1, '%s');\n",
		schema, schema, schema, marker,
	)

	cmd := exec.Command("mariadb", "--socket="+d.socket, "-u", "root")
	cmd.Stdin = strings.NewReader(script)
	if out, err := cmd.CombinedOutput(); err != nil {
		t.Fatalf("seeding real data: %v: %s", err, out)
	}
}

func (d *disposableDumpMariaDB) stop() {
	pid := readDumpPid(d.pidPath)

	_, _ = exec.Command("mariadb", "--socket="+d.socket, "-u", "root", "-e", "SHUTDOWN;").CombinedOutput()

	if pid > 0 {
		waitForDumpProcessExit(pid, 10*time.Second)
	}
}

func shortDumpSocketPath(t *testing.T) string {
	t.Helper()

	f, err := os.CreateTemp("/tmp", "lesta-bkdump-*.sock")
	if err != nil {
		t.Fatalf("allocating a short socket path: %v", err)
	}

	path := f.Name()
	_ = f.Close()
	_ = os.Remove(path)

	t.Cleanup(func() { _ = os.Remove(path) })

	return path
}

func readDumpPid(pidPath string) int {
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

func waitForDumpProcessExit(pid int, timeout time.Duration) {
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

// TestCreateWithADatabaseDumpSocketCapturesARealMysqldumpOfLiveData proves
// the whole point of this mechanism end to end: a real schema/table/row on a
// real, live MariaDB instance survives into the encrypted artifact as real
// SQL, recoverable by decrypting and reading database.tenant.v1/dump.sql
// back out, never by raw-copying the instance's own InnoDB datadir (which
// this package's own StateRoots deliberately never includes for either
// database capability).
func TestCreateWithADatabaseDumpSocketCapturesARealMysqldumpOfLiveData(t *testing.T) {
	requireRealMariaDBForDump(t)

	db := newDisposableDumpMariaDB(t)
	db.seedRealData(t, "lesta_probe_db", "hello-from-a-real-row")

	artifactsRoot := t.TempDir()
	capability := backup.New(backup.Config{
		ArtifactsRoot: artifactsRoot,
		DatabaseDumpSockets: map[string]string{
			"database.tenant.v1": db.socket,
		},
	})

	key := newTestEncryptionKey()

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(),
		map[string]any{"encryption_key": key}))
	requireStatus(t, "create with a real database dump socket", created, err, protocol.StatusApplied)

	data := decodeArtifactData(t, created.Data)
	includedRaw, ok := data["included_capabilities"].([]any)
	if !ok {
		t.Fatalf("expected included_capabilities to be a list, got %T: %v", data["included_capabilities"], data["included_capabilities"])
	}

	found := false
	for _, c := range includedRaw {
		if c == "database.tenant.v1" {
			found = true
		}
	}
	if !found {
		t.Fatalf("expected included_capabilities to contain database.tenant.v1, got %v", includedRaw)
	}

	sealed, err := os.ReadFile(data["artifact_path"].(string))
	if err != nil {
		t.Fatalf("reading artifact: %v", err)
	}

	plaintext, err := decryptForTest(key, sealed)
	if err != nil {
		t.Fatalf("decrypting artifact: %v", err)
	}

	entries := readTarGzEntries(t, plaintext)
	dump, ok := entries["database.tenant.v1/dump.sql"]
	if !ok {
		t.Fatalf("expected a database.tenant.v1/dump.sql entry, got entries: %v", mapKeys(entries))
	}

	if !strings.Contains(dump, "hello-from-a-real-row") {
		t.Fatalf("expected the dump to contain the real seeded row, got:\n%s", dump)
	}
	if !strings.Contains(dump, "CREATE TABLE") {
		t.Fatalf("expected the dump to contain a real CREATE TABLE statement, got:\n%s", dump)
	}
}

// TestCreateWithNoLiveInstanceBehindAConfiguredSocketSkipsItSilently proves
// a configured-but-absent socket (an instance simply not running on this
// node) behaves like StateRoots' own "missing directory" convention: no
// error, no entry in included_capabilities.
func TestCreateWithNoLiveInstanceBehindAConfiguredSocketSkipsItSilently(t *testing.T) {
	artifactsRoot := t.TempDir()
	capability := backup.New(backup.Config{
		ArtifactsRoot: artifactsRoot,
		DatabaseDumpSockets: map[string]string{
			"database.tenant.v1":        filepath.Join(t.TempDir(), "never-exists.sock"),
			"database.control-plane.v1": filepath.Join(t.TempDir(), "also-never-exists.sock"),
		},
	})

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(),
		map[string]any{"encryption_key": newTestEncryptionKey()}))
	requireStatus(t, "create with no live instance behind either socket", created, err, protocol.StatusApplied)

	data := decodeArtifactData(t, created.Data)
	includedRaw, _ := data["included_capabilities"].([]any)
	if len(includedRaw) != 0 {
		t.Fatalf("expected no included capabilities, got %v", includedRaw)
	}
}

func mapKeys(m map[string]string) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}

	return keys
}
