package backup_test

import (
	"bytes"
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/backup"
	"github.com/mikho/LESta/agent/internal/capability/cron"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// newBackupSudoStub writes a tiny script standing in for the real "sudo"
// binary: dumpSocket/restoreSocket invoke it as "<stub> <resolved binary>
// <args...>", so the stub's own job is just to exec straight through to
// that resolved binary (already $1), proving Config.SudoBinary's own argv
// shape is correct end-to-end against a real disposable mariadbd instance,
// without needing this test process to actually run as root.
func newBackupSudoStub(t *testing.T) string {
	t.Helper()

	path := filepath.Join(t.TempDir(), "sudo-stub.sh")
	script := "#!/bin/sh\nexec \"$@\"\n"

	if err := os.WriteFile(path, []byte(script), 0o755); err != nil {
		t.Fatalf("writing sudo stub: %v", err)
	}

	return path
}

// TestSudoBinaryRoutesDumpAndRestoreCorrectly proves Config.SudoBinary's own
// wrapping shape (dump.go's dumpSocket, restore.go's restoreSocket) against
// a real disposable mariadbd: a full create-then-restore round trip, exactly
// like TestRestoreRoundTripsRealVMailContentAndARealDatabaseDump, but with
// both the dump and the restore's own client invocation routed through the
// stub instead of invoked directly. Found deploying to a real node:
// production always sets this (unix_socket auth authenticates by checking
// the *OS* user is literally "root", which the unprivileged
// lesta-agent-daemon never is), so this is the one branch that was never
// exercised by any other test in this package before now.
func TestSudoBinaryRoutesDumpAndRestoreCorrectly(t *testing.T) {
	requireRealMariaDBForDump(t)

	db := newDisposableDumpMariaDB(t)
	db.seedRealData(t, "lesta_sudostub_probe", "hello-from-sudo-stub")

	artifactsRoot := t.TempDir()
	capability := backup.New(backup.Config{
		ArtifactsRoot: artifactsRoot,
		DatabaseDumpSockets: map[string]string{
			"database.tenant.v1": db.socket,
		},
		SudoBinary: newBackupSudoStub(t),
	})

	key := newTestEncryptionKey()

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(),
		map[string]any{"encryption_key": key}))
	requireStatus(t, "create with a sudo-stub-routed dump", created, err, protocol.StatusApplied)

	data := decodeArtifactData(t, created.Data)

	if _, err := execMariadbDrop(db.socket, "lesta_sudostub_probe"); err != nil {
		t.Fatalf("dropping the probe database to simulate data loss: %v", err)
	}

	restored, err := capability.Apply(context.Background(), newOp(protocol.OperationRestore, newTestUUID(), newTestUUID(),
		map[string]any{"artifact_path": data["artifact_path"], "encryption_key": key}))
	requireStatus(t, "restore with a sudo-stub-routed client", restored, err, protocol.StatusApplied)

	var restoredPayload struct {
		RestoredCapabilities []string `json:"restored_capabilities"`
	}
	decodeInto(t, restored.Data, &restoredPayload)

	if !containsString(restoredPayload.RestoredCapabilities, "database.tenant.v1") {
		t.Fatalf("expected database.tenant.v1 in restored_capabilities, got %v", restoredPayload.RestoredCapabilities)
	}

	out, err := execMariadbQuery(db.socket, "SELECT value FROM lesta_sudostub_probe.probe WHERE id = 1;")
	if err != nil {
		t.Fatalf("querying the restored database: %v", err)
	}
	if !strings.Contains(out, "hello-from-sudo-stub") {
		t.Fatalf("expected the restored row to contain the original seeded value, got: %s", out)
	}
}

// newCronArchiveStub writes a tiny script standing in for the real
// lesta-agent binary: archiveCronStatePrivileged invokes it as
// "<sudo stub> <this stub> cron-archive-state", so this stub's own job is
// just to reply with a real, pre-built gzip+tar payload (payloadPath) --
// exactly what a real "cron-archive-state" invocation would write to
// stdout -- proving archiveStateRoots' own routing and merge logic against
// real bytes, without needing this test process to actually run as root.
func newCronArchiveStub(t *testing.T, payloadPath string) string {
	t.Helper()

	path := filepath.Join(t.TempDir(), "agent-stub.sh")
	script := fmt.Sprintf("#!/bin/sh\nif [ \"$1\" = \"cron-archive-state\" ]; then cat %q; else exit 1; fi\n", payloadPath)

	if err := os.WriteFile(path, []byte(script), 0o755); err != nil {
		t.Fatalf("writing cron-archive-state stub: %v", err)
	}

	return path
}

// TestSudoBinaryRoutesCronArchivingCorrectly proves Config.SudoBinary/
// AgentBinaryPath's own wrapping shape for scheduler.account-cron.v1
// (archive.go's archiveCronStatePrivileged, mergeNestedArchive): a real
// cron.ArchiveState payload, produced by the real cron package against a
// real nested StateRoot, arrives inside the final encrypted artifact
// correctly re-prefixed with "scheduler.account-cron.v1/". Found deploying
// to a real node: StateRoot/accounts/<run_as> is deliberately
// root:<run_as> mode 2750 (cron package's own ensureAccountDir), never
// readable by the shared lesta group the real unprivileged
// lesta-agent-daemon runs as, so a direct walk fails with a permission
// error -- this is the one branch that was never exercised by any other
// test in this package before now.
func TestSudoBinaryRoutesCronArchivingCorrectly(t *testing.T) {
	cronStateRoot := t.TempDir()

	sidecarDir := filepath.Join(cronStateRoot, "accounts", "lesta-cron", "jobs", "sidecar")
	if err := os.MkdirAll(sidecarDir, 0o750); err != nil {
		t.Fatalf("seeding a real nested cron state directory: %v", err)
	}

	sidecarContent := []byte(`{"schedule":"* * * * *"}`)
	if err := os.WriteFile(filepath.Join(sidecarDir, "resource-1.json"), sidecarContent, 0o640); err != nil {
		t.Fatalf("seeding a real sidecar file: %v", err)
	}

	var cronArchive bytes.Buffer
	if code := cron.ArchiveState(cron.Config{StateRoot: cronStateRoot}, &cronArchive); code != 0 {
		t.Fatalf("expected cron.ArchiveState to succeed, got exit code %d", code)
	}

	payloadPath := filepath.Join(t.TempDir(), "cron-archive.tar.gz")
	if err := os.WriteFile(payloadPath, cronArchive.Bytes(), 0o600); err != nil {
		t.Fatalf("writing the real cron archive payload: %v", err)
	}

	artifactsRoot := t.TempDir()
	capability := backup.New(backup.Config{
		ArtifactsRoot: artifactsRoot,
		StateRoots: map[string]string{
			"scheduler.account-cron.v1": cronStateRoot,
		},
		SudoBinary:      newBackupSudoStub(t),
		AgentBinaryPath: newCronArchiveStub(t, payloadPath),
	})

	key := newTestEncryptionKey()

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(),
		map[string]any{"encryption_key": key}))
	requireStatus(t, "create with sudo-stub-routed cron archiving", created, err, protocol.StatusApplied)

	data := decodeArtifactData(t, created.Data)

	sealed, err := os.ReadFile(data["artifact_path"].(string))
	if err != nil {
		t.Fatalf("reading artifact: %v", err)
	}

	plaintext, err := decryptForTest(key, sealed)
	if err != nil {
		t.Fatalf("decrypting artifact: %v", err)
	}

	entries := readTarGzEntries(t, plaintext)
	got, ok := entries["scheduler.account-cron.v1/accounts/lesta-cron/jobs/sidecar/resource-1.json"]
	if !ok {
		t.Fatalf("expected a merged cron sidecar entry, got entries: %v", mapKeys(entries))
	}

	if got != string(sidecarContent) {
		t.Fatalf("expected the merged sidecar content to match exactly, got %q", got)
	}
}
