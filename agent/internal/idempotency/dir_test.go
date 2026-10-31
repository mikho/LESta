package idempotency_test

import (
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/idempotency"
	"github.com/mikho/LESta/agent/internal/protocol"
)

const testKey = "0f8e4d2a-1b3c-4d5e-8f70-123456789abc"

func TestDirReceiptSurvivesANewStore(t *testing.T) {
	path := t.TempDir()

	if err := idempotency.NewDir(path, time.Hour).Record(testKey, protocol.ResultEnvelope{IdempotencyKey: testKey, Status: protocol.StatusApplied, GenerationID: "3"}); err != nil {
		t.Fatalf("Record: %v", err)
	}

	got, ok := idempotency.NewDir(path, time.Hour).Lookup(testKey)
	if !ok || got.Status != protocol.StatusApplied || got.GenerationID != "3" {
		t.Fatalf("Lookup after a restart = %+v, %v", got, ok)
	}

	info, err := os.Stat(filepath.Join(path, testKey+".json"))
	if err != nil || info.Mode().Perm() != 0o600 {
		t.Fatalf("receipt file mode = %v (%v), want 0600", info.Mode().Perm(), err)
	}
}

func TestDirNeverMakesAPathFromAKeyThatIsNotAUUID(t *testing.T) {
	path := t.TempDir()
	store := idempotency.NewDir(path, time.Hour)

	if err := store.Record("../escape", protocol.ResultEnvelope{Status: protocol.StatusApplied}); err != nil {
		t.Fatalf("Record: %v", err)
	}

	if _, ok := store.Lookup("../escape"); ok {
		t.Fatal("a key that is not a UUID must not be remembered")
	}

	if entries, _ := os.ReadDir(path); len(entries) != 0 {
		t.Fatalf("files were written for a key that is not a UUID: %v", entries)
	}
}

func TestDirPrunesOldReceipts(t *testing.T) {
	path := t.TempDir()
	old := filepath.Join(path, "11111111-2222-4333-8444-555555555555.json")

	if err := os.WriteFile(old, []byte(`{}`), 0o600); err != nil {
		t.Fatal(err)
	}
	if err := os.Chtimes(old, time.Now().Add(-48*time.Hour), time.Now().Add(-48*time.Hour)); err != nil {
		t.Fatal(err)
	}

	if err := idempotency.NewDir(path, 24*time.Hour).Record(testKey, protocol.ResultEnvelope{Status: protocol.StatusApplied}); err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(old); !os.IsNotExist(err) {
		t.Fatalf("a receipt older than the maximum age was kept: %v", err)
	}
}
