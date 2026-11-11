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
	"github.com/mikho/LESta/agent/internal/protocol"
)

// mailStateWith builds a mail state root holding one message and returns it with the live mail root
// the restore writes into.
func mailStateWith(t *testing.T, body string) (stateRoot, liveRoot string) {
	t.Helper()

	stateRoot = t.TempDir()
	dir := filepath.Join(stateRoot, "vmail", "stream-example.com", "alice", "cur")

	if err := os.MkdirAll(dir, 0o750); err != nil {
		t.Fatal(err)
	}

	if err := os.WriteFile(filepath.Join(dir, "1700000000.M1.host,S=10"), []byte(body), 0o640); err != nil {
		t.Fatal(err)
	}

	return stateRoot, filepath.Join(t.TempDir(), "vmail")
}

func createMailArtifact(t *testing.T, cfg backup.Config) (string, string) {
	t.Helper()

	capability := backup.New(cfg)
	key := newTestEncryptionKey()

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), map[string]any{"encryption_key": key}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	return decodeArtifactData(t, created.Data)["artifact_path"].(string), key
}

func restoreOp(path, key string) protocol.OperationEnvelope {
	return newOp(protocol.OperationRestore, newTestUUID(), newTestUUID(), map[string]any{"artifact_path": path, "encryption_key": key})
}

func TestAStreamedWholeNodeRestoreBringsMailBack(t *testing.T) {
	stateRoot, live := mailStateWith(t, "streamed hello")
	cfg := backup.Config{ArtifactsRoot: t.TempDir(), StateRoots: map[string]string{"mail.smtp-imap.v1": stateRoot}, VMailRoot: live}

	path, key := createMailArtifact(t, cfg)

	result, err := backup.New(cfg).Apply(context.Background(), restoreOp(path, key))
	requireStatus(t, "restore", result, err, protocol.StatusApplied)

	got, err := os.ReadFile(filepath.Join(live, "stream-example.com", "alice", "cur", "1700000000.M1.host,S=10"))
	if err != nil || string(got) != "streamed hello" {
		t.Fatalf("the message did not come back: %q %v", got, err)
	}

	assertNoRestoreLeftovers(t, cfg.ArtifactsRoot, filepath.Dir(live))
}

func TestACutShortOrTamperedArtifactChangesNothingOnTheNode(t *testing.T) {
	stateRoot, live := mailStateWith(t, "must not appear")
	cfg := backup.Config{ArtifactsRoot: t.TempDir(), StateRoots: map[string]string{"mail.smtp-imap.v1": stateRoot}, VMailRoot: live}

	path, key := createMailArtifact(t, cfg)

	sealed, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}

	flipped := append([]byte(nil), sealed...)
	flipped[len(flipped)/2] ^= 0xff

	for name, damaged := range map[string][]byte{"cut short": sealed[:len(sealed)-30], "tampered": flipped} {
		damagedPath := filepath.Join(cfg.ArtifactsRoot, strings.ReplaceAll(name, " ", "-")+".tar.enc")
		if err := os.WriteFile(damagedPath, damaged, 0o600); err != nil {
			t.Fatal(err)
		}

		result, err := backup.New(cfg).Apply(context.Background(), restoreOp(damagedPath, key))
		if err != nil {
			t.Fatalf("%s: %v", name, err)
		}

		if result.Status != protocol.StatusFailed {
			t.Errorf("%s: expected a failed restore, got %s", name, result.Status)
		}

		if _, err := os.Stat(filepath.Join(live, "stream-example.com")); err == nil {
			t.Errorf("%s: mail was made live from a backup that does not authenticate", name)
		}

		assertNoRestoreLeftovers(t, cfg.ArtifactsRoot, filepath.Dir(live))
	}
}

func TestAnArtifactFromBeforeStreamingStillRestores(t *testing.T) {
	stateRoot, live := mailStateWith(t, "legacy hello")
	cfg := backup.Config{ArtifactsRoot: t.TempDir(), StateRoots: map[string]string{"mail.smtp-imap.v1": stateRoot}, VMailRoot: live}

	// Seal the same archive the old way: one AES-GCM message.
	streamed, key := createMailArtifact(t, cfg)

	sealed, err := os.ReadFile(streamed)
	if err != nil {
		t.Fatal(err)
	}

	plaintext, err := decryptForTest(key, sealed)
	if err != nil {
		t.Fatal(err)
	}

	legacy, err := backup.EncryptLegacyForTest(key, plaintext)
	if err != nil {
		t.Fatal(err)
	}

	legacyPath := filepath.Join(cfg.ArtifactsRoot, "legacy.tar.enc")
	if err := os.WriteFile(legacyPath, legacy, 0o600); err != nil {
		t.Fatal(err)
	}

	result, err := backup.New(cfg).Apply(context.Background(), restoreOp(legacyPath, key))
	requireStatus(t, "restore of a legacy artifact", result, err, protocol.StatusApplied)

	if got, _ := os.ReadFile(filepath.Join(live, "stream-example.com", "alice", "cur", "1700000000.M1.host,S=10")); string(got) != "legacy hello" {
		t.Fatalf("the legacy message did not come back: %q", got)
	}

	// And it is still readable by the plain decrypt used for downloads.
	if back, err := decryptForTest(key, legacy); err != nil || !bytes.Equal(back, plaintext) {
		t.Fatalf("a legacy artifact must still decrypt: %v", err)
	}
}

// requestRecorderStub stands in for the agent binary on the privileged paths: "backup-archive-mail-state"
// answers with payload and "backup-restore-mail-files" keeps what it is sent in recorded.
func requestRecorderStub(t *testing.T, payload, recorded string) string {
	t.Helper()

	path := filepath.Join(t.TempDir(), "agent-stub.sh")
	script := fmt.Sprintf("#!/bin/sh\ncase \"$1\" in\n backup-archive-mail-state) cat %q ;;\n backup-restore-mail-files) cat > %q ;;\n *) exit 1 ;;\nesac\n", payload, recorded)

	if err := os.WriteFile(path, []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}

	return path
}

func TestThePrivilegedMailPathsStreamBothWays(t *testing.T) {
	stateRoot, live := mailStateWith(t, "via the helper")

	var archive bytes.Buffer
	if code := backup.ArchiveMailState(backup.Config{StateRoots: map[string]string{"mail.smtp-imap.v1": stateRoot}}, &archive); code != 0 {
		t.Fatalf("ArchiveMailState exit code %d", code)
	}

	dir := t.TempDir()
	payload := filepath.Join(dir, "mail.tar.gz")
	recorded := filepath.Join(dir, "request.tar.gz")

	if err := os.WriteFile(payload, archive.Bytes(), 0o600); err != nil {
		t.Fatal(err)
	}

	cfg := backup.Config{
		ArtifactsRoot:   t.TempDir(),
		StateRoots:      map[string]string{"mail.smtp-imap.v1": stateRoot},
		VMailRoot:       live,
		SudoBinary:      newBackupSudoStub(t),
		AgentBinaryPath: requestRecorderStub(t, payload, recorded),
	}

	path, key := createMailArtifact(t, cfg)

	result, err := backup.New(cfg).Apply(context.Background(), restoreOp(path, key))
	requireStatus(t, "restore through the helper", result, err, protocol.StatusApplied)

	// The request the daemon sent is exactly what the root helper accepts, and it commits it.
	request, err := os.Open(recorded)
	if err != nil {
		t.Fatalf("the helper was never called: %v", err)
	}

	defer func() { _ = request.Close() }()

	if code := backup.RestoreMailFiles(backup.Config{VMailRoot: live}, request); code != 0 {
		t.Fatalf("the helper refused the daemon's request (exit %d)", code)
	}

	if got, _ := os.ReadFile(filepath.Join(live, "stream-example.com", "alice", "cur", "1700000000.M1.host,S=10")); string(got) != "via the helper" {
		t.Fatalf("the message did not come back through the helper: %q", got)
	}
}

func assertNoRestoreLeftovers(t *testing.T, artifactsRoot, liveParent string) {
	t.Helper()

	if matches, _ := filepath.Glob(filepath.Join(artifactsRoot, ".restore-*")); len(matches) != 0 {
		t.Errorf("a restore work directory was left behind: %v", matches)
	}

	if matches, _ := filepath.Glob(filepath.Join(liveParent, "vmail-restore-*")); len(matches) != 0 {
		t.Errorf("a staging directory was left behind: %v", matches)
	}
}
