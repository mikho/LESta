package backup_test

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/backup"
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
