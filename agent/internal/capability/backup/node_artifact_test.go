package backup_test

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/backup"
	"github.com/mikho/LESta/agent/internal/protocol"
)

func TestCreateWritesAStreamedArtifactWhoseChecksumAndSizeMatchTheFile(t *testing.T) {
	artifactsRoot := t.TempDir()
	stateRoots, _ := buildFakeStateRoots(t)
	capability := backup.New(backup.Config{ArtifactsRoot: artifactsRoot, StateRoots: stateRoots})

	key := newTestEncryptionKey()

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), map[string]any{"encryption_key": key}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	data := decodeArtifactData(t, created.Data)
	path := data["artifact_path"].(string)

	sealed, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("reading the artifact: %v", err)
	}

	if !bytes.HasPrefix(sealed, []byte("LESTABK1")) {
		t.Fatalf("expected the streamed format, got a header of %q", sealed[:8])
	}

	if int64(data["size_bytes"].(float64)) != int64(len(sealed)) {
		t.Fatalf("size_bytes %v does not match the %d bytes on disk", data["size_bytes"], len(sealed))
	}

	if want := checksumOfBytes(sealed); data["checksum"] != want {
		t.Fatalf("checksum %v does not match the file's %s", data["checksum"], want)
	}

	entries, _ := os.ReadDir(artifactsRoot)
	for _, entry := range entries {
		if strings.HasSuffix(entry.Name(), ".part") || strings.HasPrefix(entry.Name(), ".dump-") {
			t.Fatalf("a temporary %q was left behind", entry.Name())
		}
	}
}

func TestCreateReplayKeepsTheSameArtifactAndChecksum(t *testing.T) {
	artifactsRoot := t.TempDir()
	stateRoots, _ := buildFakeStateRoots(t)
	capability := backup.New(backup.Config{ArtifactsRoot: artifactsRoot, StateRoots: stateRoots})

	key := newTestEncryptionKey()
	id := newTestUUID()

	first, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, id, newTestUUID(), map[string]any{"encryption_key": key}))
	requireStatus(t, "first create", first, err, protocol.StatusApplied)

	second, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, id, newTestUUID(), map[string]any{"encryption_key": key}))
	requireStatus(t, "replayed create", second, err, protocol.StatusAlreadyApplied)

	if decodeArtifactData(t, first.Data)["checksum"] != decodeArtifactData(t, second.Data)["checksum"] {
		t.Fatal("a replay must report the checksum of the artifact already on disk")
	}
}

func TestADamagedStreamedArtifactDoesNotDecrypt(t *testing.T) {
	artifactsRoot := t.TempDir()
	stateRoots, _ := buildFakeStateRoots(t)
	capability := backup.New(backup.Config{ArtifactsRoot: artifactsRoot, StateRoots: stateRoots})

	key := newTestEncryptionKey()

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), map[string]any{"encryption_key": key}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	path := decodeArtifactData(t, created.Data)["artifact_path"].(string)

	sealed, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("reading the artifact: %v", err)
	}

	if _, err := decryptForTest(key, sealed[:len(sealed)-20]); err == nil {
		t.Fatal("a cut-short artifact must not decrypt")
	}

	if _, err := decryptForTest(newTestEncryptionKey(), sealed); err == nil {
		t.Fatal("a wrong key must not decrypt")
	}
}

func TestALargeStateFileIsArchivedWithoutBeingHeldWhole(t *testing.T) {
	artifactsRoot := t.TempDir()
	stateRoot := t.TempDir()

	big := bytes.Repeat([]byte("0123456789abcdef"), 4<<20) // 64 MiB
	if err := os.WriteFile(filepath.Join(stateRoot, "big.conf"), big, 0o644); err != nil {
		t.Fatalf("writing the big file: %v", err)
	}

	capability := backup.New(backup.Config{ArtifactsRoot: artifactsRoot, StateRoots: map[string]string{"web.nginx.v1": stateRoot}})

	key := newTestEncryptionKey()

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), map[string]any{"encryption_key": key}))
	requireStatus(t, "create of a large state", created, err, protocol.StatusApplied)

	sealed, err := os.ReadFile(decodeArtifactData(t, created.Data)["artifact_path"].(string))
	if err != nil {
		t.Fatalf("reading the artifact: %v", err)
	}

	plaintext, err := decryptForTest(key, sealed)
	if err != nil {
		t.Fatalf("decrypting: %v", err)
	}

	if got := readTarGzEntries(t, plaintext)["web.nginx.v1/big.conf"]; got != string(big) {
		t.Fatalf("the big file did not round trip (%d bytes back)", len(got))
	}
}

func checksumOfBytes(b []byte) string {
	sum := sha256.Sum256(b)

	return "sha256:" + hex.EncodeToString(sum[:])
}
