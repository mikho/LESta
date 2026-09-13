package backup_test

import (
	"context"
	"encoding/json"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/backup"
	"github.com/mikho/LESta/agent/internal/protocol"
)

func TestApplyRejectsAMissingEncryptionKeyOnRestore(t *testing.T) {
	capability := backup.New(backup.Config{ArtifactsRoot: t.TempDir()})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationRestore, newTestUUID(), newTestUUID(),
		map[string]any{"artifact_path": "/does/not/matter.tar.enc"}))
	requireStatus(t, "restore with no encryption_key", result, err, protocol.StatusRejected)
	requireErrorCode(t, "restore with no encryption_key", result, "invalid_encryption_key")
}

func TestApplyRejectsAMissingArtifactPathOnRestore(t *testing.T) {
	capability := backup.New(backup.Config{ArtifactsRoot: t.TempDir()})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationRestore, newTestUUID(), newTestUUID(),
		map[string]any{"encryption_key": newTestEncryptionKey()}))
	requireStatus(t, "restore with no artifact_path", result, err, protocol.StatusRejected)
	requireErrorCode(t, "restore with no artifact_path", result, "invalid_artifact_path")
}

func TestApplyRejectsARestoreArtifactPathOutsideTheArtifactsRoot(t *testing.T) {
	capability := backup.New(backup.Config{ArtifactsRoot: t.TempDir()})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationRestore, newTestUUID(), newTestUUID(),
		map[string]any{"artifact_path": "/etc/passwd", "encryption_key": newTestEncryptionKey()}))
	requireStatus(t, "restore outside artifacts root", result, err, protocol.StatusRejected)
	requireErrorCode(t, "restore outside artifacts root", result, "artifact_path_outside_root")
}

func TestApplyRejectsARestoreOfANonexistentArtifact(t *testing.T) {
	artifactsRoot := t.TempDir()
	capability := backup.New(backup.Config{ArtifactsRoot: artifactsRoot})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationRestore, newTestUUID(), newTestUUID(),
		map[string]any{"artifact_path": filepath.Join(artifactsRoot, "never-existed.tar.enc"), "encryption_key": newTestEncryptionKey()}))
	requireStatus(t, "restore of a nonexistent artifact", result, err, protocol.StatusRejected)
	requireErrorCode(t, "restore of a nonexistent artifact", result, "artifact_not_found")
}

func TestApplyRejectsARestoreWithTheWrongEncryptionKey(t *testing.T) {
	artifactsRoot := t.TempDir()
	capability := backup.New(backup.Config{ArtifactsRoot: artifactsRoot, StateRoots: map[string]string{"web.nginx.v1": nonEmptyStateRoot(t)}})

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(),
		map[string]any{"encryption_key": newTestEncryptionKey()}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	data := decodeArtifactData(t, created.Data)

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationRestore, newTestUUID(), newTestUUID(),
		map[string]any{"artifact_path": data["artifact_path"], "encryption_key": newTestEncryptionKey()}))
	requireStatus(t, "restore with the wrong key", result, err, protocol.StatusFailed)
	requireErrorCode(t, "restore with the wrong key", result, "decryption_failed")
}

func TestRestoreIsExemptFromTheIdempotencyReceiptCache(t *testing.T) {
	artifactsRoot := t.TempDir()
	capability := backup.New(backup.Config{ArtifactsRoot: artifactsRoot, StateRoots: map[string]string{"web.nginx.v1": nonEmptyStateRoot(t)}})

	key := newTestEncryptionKey()
	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(),
		map[string]any{"encryption_key": key}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	data := decodeArtifactData(t, created.Data)
	idem := newTestUUID()

	first, err := capability.Apply(context.Background(), newOp(protocol.OperationRestore, newTestUUID(), idem,
		map[string]any{"artifact_path": data["artifact_path"], "encryption_key": key}))
	requireStatus(t, "first restore", first, err, protocol.StatusApplied)

	// A second restore with the identical idempotency key must genuinely
	// re-run (StatusApplied again), never silently replay a cached
	// StatusAlreadyApplied the way create/delete would.
	second, err := capability.Apply(context.Background(), newOp(protocol.OperationRestore, newTestUUID(), idem,
		map[string]any{"artifact_path": data["artifact_path"], "encryption_key": key}))
	requireStatus(t, "second restore, same idempotency key", second, err, protocol.StatusApplied)
}

func nonEmptyStateRoot(t *testing.T) string {
	t.Helper()

	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, "placeholder.conf"), []byte("placeholder"), 0o644); err != nil {
		t.Fatalf("seeding a non-empty state root: %v", err)
	}

	return dir
}

// TestRestoreRoundTripsRealVMailContentAndARealDatabaseDump proves the whole
// feature end to end: real received-mail bytes and a real seeded database
// row both survive create -> (simulated disaster: live vmail wiped, live
// database emptied) -> restore, using the exact same disposable-MariaDB
// harness dump_test.go already established.
func TestRestoreRoundTripsRealVMailContentAndARealDatabaseDump(t *testing.T) {
	requireRealMariaDBForDump(t)

	db := newDisposableDumpMariaDB(t)
	db.seedRealData(t, "lesta_restore_probe", "hello-before-disaster")

	mailStateRoot := t.TempDir()
	vmailArchiveRoot := filepath.Join(mailStateRoot, "vmail")
	domain := "restore-example.com"
	mailboxDir := filepath.Join(vmailArchiveRoot, domain, "sales", "cur")
	if err := os.MkdirAll(mailboxDir, 0o750); err != nil {
		t.Fatalf("seeding a real maildir: %v", err)
	}
	messagePath := filepath.Join(mailboxDir, "1700000000.M1.host,S=42")
	messageBody := "Subject: a real message worth restoring\r\n\r\nHello.\r\n"
	if err := os.WriteFile(messagePath, []byte(messageBody), 0o640); err != nil {
		t.Fatalf("writing a real message: %v", err)
	}

	liveVMailRoot := t.TempDir()

	artifactsRoot := t.TempDir()
	capability := backup.New(backup.Config{
		ArtifactsRoot: artifactsRoot,
		StateRoots: map[string]string{
			"mail.smtp-imap.v1": mailStateRoot,
		},
		DatabaseDumpSockets: map[string]string{
			"database.tenant.v1": db.socket,
		},
		VMailRoot: liveVMailRoot,
	})

	key := newTestEncryptionKey()

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(),
		map[string]any{"encryption_key": key}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	data := decodeArtifactData(t, created.Data)

	// Simulate the disaster this feature exists for: the live vmail root
	// this node currently serves out of is empty (a fresh/rebuilt node),
	// and the live database's own probe row is gone.
	if _, err := execMariadbDrop(db.socket, "lesta_restore_probe"); err != nil {
		t.Fatalf("dropping the probe database to simulate data loss: %v", err)
	}

	restored, err := capability.Apply(context.Background(), newOp(protocol.OperationRestore, newTestUUID(), newTestUUID(),
		map[string]any{"artifact_path": data["artifact_path"], "encryption_key": key}))
	requireStatus(t, "restore", restored, err, protocol.StatusApplied)

	var restoredPayload struct {
		RestoredCapabilities []string `json:"restored_capabilities"`
	}
	decodeInto(t, restored.Data, &restoredPayload)

	if !containsString(restoredPayload.RestoredCapabilities, "mail.smtp-imap.v1") {
		t.Fatalf("expected mail.smtp-imap.v1 in restored_capabilities, got %v", restoredPayload.RestoredCapabilities)
	}
	if !containsString(restoredPayload.RestoredCapabilities, "database.tenant.v1") {
		t.Fatalf("expected database.tenant.v1 in restored_capabilities, got %v", restoredPayload.RestoredCapabilities)
	}

	restoredMessagePath := filepath.Join(liveVMailRoot, domain, "sales", "cur", "1700000000.M1.host,S=42")
	restoredBytes, err := os.ReadFile(restoredMessagePath)
	if err != nil {
		t.Fatalf("expected the real message to be restored at %s: %v", restoredMessagePath, err)
	}
	if string(restoredBytes) != messageBody {
		t.Fatalf("expected the restored message body to match exactly, got %q", string(restoredBytes))
	}

	out, err := execMariadbQuery(db.socket, "SELECT value FROM lesta_restore_probe.probe WHERE id = 1;")
	if err != nil {
		t.Fatalf("querying the restored database: %v", err)
	}
	if !strings.Contains(out, "hello-before-disaster") {
		t.Fatalf("expected the restored row to contain the original seeded value, got: %s", out)
	}
}

func containsString(list []string, want string) bool {
	for _, s := range list {
		if s == want {
			return true
		}
	}

	return false
}

func decodeInto(t *testing.T, raw json.RawMessage, v any) {
	t.Helper()

	if len(raw) == 0 {
		t.Fatal("expected a non-empty Data field on a successful result")
	}

	if err := json.Unmarshal(raw, v); err != nil {
		t.Fatalf("decoding Data: %v", err)
	}
}

// execMariadbDrop and execMariadbQuery run a real, one-shot statement
// against a disposableDumpMariaDB's own socket, entirely independent of
// BackupCapability: the same "prove it against the real thing, not just
// that Apply() returned a status" discipline dump_test.go's own
// seedRealData already uses.
func execMariadbDrop(socket, database string) (string, error) {
	out, err := exec.Command("mariadb", "--socket="+socket, "-u", "root", "-e", "DROP DATABASE "+database+";").CombinedOutput()

	return string(out), err
}

func execMariadbQuery(socket, query string) (string, error) {
	out, err := exec.Command("mariadb", "--socket="+socket, "-u", "root", "--batch", "--skip-column-names", "-e", query).CombinedOutput()

	return string(out), err
}
