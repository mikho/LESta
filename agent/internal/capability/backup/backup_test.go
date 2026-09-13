package backup_test

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/backup"
	"github.com/mikho/LESta/agent/internal/protocol"
)

func decryptForTest(hexKey string, sealed []byte) ([]byte, error) {
	return backup.Decrypt(hexKey, sealed)
}

// readTarGzEntries decrypted-plaintext-decodes a real gzip+tar stream back
// into a flat map of entry name to file contents, skipping directory
// entries, so a test can assert on exactly which files an archive contains.
func readTarGzEntries(t *testing.T, plaintext []byte) map[string]string {
	t.Helper()

	gz, err := gzip.NewReader(bytes.NewReader(plaintext))
	if err != nil {
		t.Fatalf("opening gzip reader: %v", err)
	}
	defer gz.Close()

	tr := tar.NewReader(gz)
	entries := make(map[string]string)

	for {
		hdr, err := tr.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			t.Fatalf("reading tar entry: %v", err)
		}

		if hdr.Typeflag == tar.TypeDir {
			continue
		}

		content, err := io.ReadAll(tr)
		if err != nil {
			t.Fatalf("reading tar entry content for %s: %v", hdr.Name, err)
		}

		entries[hdr.Name] = string(content)
	}

	return entries
}

func newTestUUID() string {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		panic(fmt.Sprintf("generating test UUID: %v", err))
	}

	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80

	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16])
}

func newTestEncryptionKey() string {
	var key [32]byte
	if _, err := rand.Read(key[:]); err != nil {
		panic(fmt.Sprintf("generating test encryption key: %v", err))
	}

	return hex.EncodeToString(key[:])
}

func newOp(operation protocol.Operation, resourceID, idempotencyKey string, payload map[string]any) protocol.OperationEnvelope {
	raw, err := json.Marshal(payload)
	if err != nil {
		panic(fmt.Sprintf("marshaling test payload: %v", err))
	}

	now := time.Now().UTC()

	return protocol.OperationEnvelope{
		ProtocolVersion:     "1",
		Capability:          "backup.encrypted-artifacts.v1",
		Operation:           operation,
		ResourceID:          resourceID,
		DesiredStateVersion: 1,
		IdempotencyKey:      idempotencyKey,
		CorrelationID:       newTestUUID(),
		Deadline:            now.Add(10 * time.Second),
		IssuedAt:            now,
		RequestDigest:       "sha256:" + strings.Repeat("0", 64),
		Payload:             raw,
	}
}

func requireStatus(t *testing.T, label string, result protocol.ResultEnvelope, err error, want protocol.Status) {
	t.Helper()

	if err != nil {
		t.Fatalf("%s: Apply returned an error (no verdict reached): %v", label, err)
	}
	if result.Status != want {
		t.Fatalf("%s: expected status %s, got %s (errors=%+v)", label, want, result.Status, result.Errors)
	}
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

func TestApplyRejectsAMissingEncryptionKeyOnCreate(t *testing.T) {
	capability := backup.New(backup.Config{ArtifactsRoot: t.TempDir()})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), map[string]any{}))
	requireStatus(t, "create with no encryption_key", result, err, protocol.StatusRejected)
	requireErrorCode(t, "create with no encryption_key", result, "invalid_encryption_key")
}

func TestApplyRejectsATooShortEncryptionKeyOnCreate(t *testing.T) {
	capability := backup.New(backup.Config{ArtifactsRoot: t.TempDir()})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(),
		map[string]any{"encryption_key": "deadbeef"}))
	requireStatus(t, "create with a too-short encryption_key", result, err, protocol.StatusRejected)
	requireErrorCode(t, "create with a too-short encryption_key", result, "invalid_encryption_key")
}

func TestApplyRejectsAMissingArtifactPathOnDelete(t *testing.T) {
	capability := backup.New(backup.Config{ArtifactsRoot: t.TempDir()})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationDelete, newTestUUID(), newTestUUID(), map[string]any{}))
	requireStatus(t, "delete with no artifact_path", result, err, protocol.StatusRejected)
	requireErrorCode(t, "delete with no artifact_path", result, "invalid_artifact_path")
}

func TestApplyRejectsAnUnsupportedOperation(t *testing.T) {
	capability := backup.New(backup.Config{ArtifactsRoot: t.TempDir()})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationSuspend, newTestUUID(), newTestUUID(), map[string]any{}))
	requireStatus(t, "suspend", result, err, protocol.StatusRejected)
	requireErrorCode(t, "suspend", result, "unsupported_operation")
}

func TestApplyRejectsAMissingArtifactPathOnObserve(t *testing.T) {
	capability := backup.New(backup.Config{ArtifactsRoot: t.TempDir()})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, newTestUUID(), newTestUUID(), map[string]any{}))
	requireStatus(t, "observe with no artifact_path", result, err, protocol.StatusRejected)
	requireErrorCode(t, "observe with no artifact_path", result, "invalid_artifact_path")
}

func TestApplyRejectsAnObserveArtifactPathOutsideTheArtifactsRoot(t *testing.T) {
	root := t.TempDir()
	capability := backup.New(backup.Config{ArtifactsRoot: root})

	outside := filepath.Join(t.TempDir(), "not-a-backup.tar.enc")
	if err := os.WriteFile(outside, []byte("not a real artifact"), 0o600); err != nil {
		t.Fatalf("writing decoy file: %v", err)
	}

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, newTestUUID(), newTestUUID(),
		map[string]any{"artifact_path": outside}))
	requireStatus(t, "observe outside artifacts root", result, err, protocol.StatusRejected)
	requireErrorCode(t, "observe outside artifacts root", result, "artifact_path_outside_root")
}

func TestApplyRejectsAnObserveOfANonexistentArtifact(t *testing.T) {
	root := t.TempDir()
	capability := backup.New(backup.Config{ArtifactsRoot: root})

	missing := filepath.Join(root, "never-existed.tar.enc")

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, newTestUUID(), newTestUUID(),
		map[string]any{"artifact_path": missing}))
	requireStatus(t, "observe of a missing artifact", result, err, protocol.StatusRejected)
	requireErrorCode(t, "observe of a missing artifact", result, "artifact_not_found")
}

func TestObserveReportsTheSealedArtifactBytesWhichDecryptBackToTheOriginalArchive(t *testing.T) {
	artifactsRoot := t.TempDir()
	stateRoots, _ := buildFakeStateRoots(t)
	capability := backup.New(backup.Config{ArtifactsRoot: artifactsRoot, StateRoots: stateRoots})

	key := newTestEncryptionKey()

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(),
		map[string]any{"encryption_key": key}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	artifactPath := decodeArtifactData(t, created.Data)["artifact_path"].(string)

	observed, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, newTestUUID(), newTestUUID(),
		map[string]any{"artifact_path": artifactPath}))
	requireStatus(t, "observe", observed, err, protocol.StatusApplied)

	var data struct {
		ArtifactBase64 string `json:"artifact_base64"`
	}
	if err := json.Unmarshal(observed.Data, &data); err != nil {
		t.Fatalf("unmarshaling observe data: %v", err)
	}
	if data.ArtifactBase64 == "" {
		t.Fatal("expected a non-empty artifact_base64")
	}

	sealed, err := base64.StdEncoding.DecodeString(data.ArtifactBase64)
	if err != nil {
		t.Fatalf("decoding artifact_base64: %v", err)
	}

	onDisk, err := os.ReadFile(artifactPath)
	if err != nil {
		t.Fatalf("reading the real artifact from disk: %v", err)
	}
	if string(sealed) != string(onDisk) {
		t.Fatal("observe must report exactly the same sealed bytes that are on disk, unmodified")
	}

	plaintext, err := decryptForTest(key, sealed)
	if err != nil {
		t.Fatalf("decrypting the observed bytes: %v", err)
	}

	entries := readTarGzEntries(t, plaintext)
	if got := entries["web.nginx.v1/sites/example.com.conf"]; got != "server { listen 80; }" {
		t.Fatalf("entry web.nginx.v1/sites/example.com.conf: got %q, want %q", got, "server { listen 80; }")
	}
}

func TestObserveIsExemptFromTheIdempotencyReceiptCacheAndAlwaysRereadsTheArtifact(t *testing.T) {
	artifactsRoot := t.TempDir()
	stateRoots, _ := buildFakeStateRoots(t)
	capability := backup.New(backup.Config{ArtifactsRoot: artifactsRoot, StateRoots: stateRoots})

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(),
		map[string]any{"encryption_key": newTestEncryptionKey()}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	artifactPath := decodeArtifactData(t, created.Data)["artifact_path"].(string)
	idempotencyKey := newTestUUID()

	first, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, newTestUUID(), idempotencyKey,
		map[string]any{"artifact_path": artifactPath}))
	requireStatus(t, "first observe", first, err, protocol.StatusApplied)

	// A second observe reusing the exact same idempotency key must still
	// re-read the artifact fresh, not replay a cached receipt from the
	// first call -- if it were cached, this would return StatusAlreadyApplied
	// the way Create's own idempotency replay does.
	second, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, newTestUUID(), idempotencyKey,
		map[string]any{"artifact_path": artifactPath}))
	requireStatus(t, "second observe, same idempotency key", second, err, protocol.StatusApplied)
}

func TestApplyRejectsADeleteArtifactPathOutsideTheArtifactsRoot(t *testing.T) {
	root := t.TempDir()
	capability := backup.New(backup.Config{ArtifactsRoot: root})

	outside := filepath.Join(t.TempDir(), "not-a-backup.tar.enc")
	if err := os.WriteFile(outside, []byte("not a real artifact"), 0o600); err != nil {
		t.Fatalf("writing decoy file: %v", err)
	}

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationDelete, newTestUUID(), newTestUUID(),
		map[string]any{"artifact_path": outside}))
	requireStatus(t, "delete outside artifacts root", result, err, protocol.StatusRejected)
	requireErrorCode(t, "delete outside artifacts root", result, "artifact_path_outside_root")

	if _, statErr := os.Stat(outside); statErr != nil {
		t.Fatalf("decoy file outside the artifacts root should have been left untouched: %v", statErr)
	}
}

func TestApplyRejectsADeleteArtifactPathEscapingViaDotDot(t *testing.T) {
	root := t.TempDir()
	capability := backup.New(backup.Config{ArtifactsRoot: root})

	escaping := filepath.Join(root, "..", "escaped.tar.enc")

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationDelete, newTestUUID(), newTestUUID(),
		map[string]any{"artifact_path": escaping}))
	requireStatus(t, "delete escaping via ..", result, err, protocol.StatusRejected)
	requireErrorCode(t, "delete escaping via ..", result, "artifact_path_outside_root")
}

// buildFakeStateRoots creates two real, non-empty directories (standing in
// for two other capabilities' own rendered state, e.g. nginx/bind9) and one
// empty directory (standing in for a capability installed but never
// rendered), returning a Config.StateRoots map plus the exact file contents
// written, so a test can assert the resulting archive really contains them.
func buildFakeStateRoots(t *testing.T) (map[string]string, map[string]string) {
	t.Helper()

	nginxRoot := t.TempDir()
	bindRoot := t.TempDir()
	emptyRoot := t.TempDir()

	files := map[string]string{
		filepath.Join(nginxRoot, "sites", "example.com.conf"): "server { listen 80; }",
		filepath.Join(bindRoot, "example.com.zone"):           "$TTL 3600\n@ IN SOA ns1. admin. (1 3600 600 604800 3600)\n",
	}

	for path, content := range files {
		if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
			t.Fatalf("creating fake state dir: %v", err)
		}
		if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
			t.Fatalf("writing fake state file: %v", err)
		}
	}

	stateRoots := map[string]string{
		"web.nginx.v1":      nginxRoot,
		"dns.bind9.v1":      bindRoot,
		"mail.smtp-imap.v1": emptyRoot,
	}

	return stateRoots, files
}

func decodeArtifactData(t *testing.T, raw json.RawMessage) map[string]any {
	t.Helper()

	if len(raw) == 0 {
		t.Fatal("expected a non-empty Data field on a successful create result")
	}

	var data map[string]any
	if err := json.Unmarshal(raw, &data); err != nil {
		t.Fatalf("decoding Data: %v", err)
	}

	return data
}

func TestCreateProducesARealEncryptedArtifactContainingOnlyNonEmptyStateRoots(t *testing.T) {
	artifactsRoot := t.TempDir()
	stateRoots, _ := buildFakeStateRoots(t)
	capability := backup.New(backup.Config{ArtifactsRoot: artifactsRoot, StateRoots: stateRoots})

	key := newTestEncryptionKey()
	resourceID := newTestUUID()

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, newTestUUID(),
		map[string]any{"label": "nightly", "encryption_key": key}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	data := decodeArtifactData(t, created.Data)

	included, ok := data["included_capabilities"].([]any)
	if !ok || len(included) != 2 {
		t.Fatalf("expected exactly 2 included_capabilities (the empty mail root excluded), got %+v", data["included_capabilities"])
	}
	for _, c := range included {
		if c != "web.nginx.v1" && c != "dns.bind9.v1" {
			t.Fatalf("unexpected included capability %v; mail.smtp-imap.v1's own empty root must never be included", c)
		}
	}

	artifactPath, _ := data["artifact_path"].(string)
	if artifactPath == "" {
		t.Fatal("expected a non-empty artifact_path")
	}
	if filepath.Dir(artifactPath) != artifactsRoot {
		t.Fatalf("expected artifact_path under %s, got %s", artifactsRoot, artifactPath)
	}

	sealed, err := os.ReadFile(artifactPath)
	if err != nil {
		t.Fatalf("reading written artifact: %v", err)
	}

	if created.ObservedStateDigest == "" || created.ObservedStateDigest == "sha256:"+strings.Repeat("0", 64) {
		t.Fatalf("expected a real observed_state_digest, got %q", created.ObservedStateDigest)
	}

	// The artifact on disk must genuinely be ciphertext, not the plaintext
	// tar.gz: it must not start with gzip's own 0x1f 0x8b magic bytes.
	if len(sealed) >= 2 && sealed[0] == 0x1f && sealed[1] == 0x8b {
		t.Fatal("artifact on disk looks like an unencrypted gzip stream; it must be AES-GCM sealed")
	}

	// A wrong key must never decrypt it.
	if _, err := decryptForTest(newTestEncryptionKey(), sealed); err == nil {
		t.Fatal("decrypting the artifact with the wrong key unexpectedly succeeded")
	}

	// The real key must decrypt it back to a genuine gzip stream containing
	// exactly the two non-empty capabilities' own files, correctly prefixed.
	plaintext, err := decryptForTest(key, sealed)
	if err != nil {
		t.Fatalf("decrypting the artifact with the real key: %v", err)
	}

	entries := readTarGzEntries(t, plaintext)
	if got, want := entries["web.nginx.v1/sites/example.com.conf"], "server { listen 80; }"; got != want {
		t.Fatalf("nginx entry: got %q, want %q", got, want)
	}
	if _, ok := entries["dns.bind9.v1/example.com.zone"]; !ok {
		t.Fatalf("expected a dns.bind9.v1/example.com.zone entry, got entries %+v", entries)
	}
	for name := range entries {
		if strings.HasPrefix(name, "mail.smtp-imap.v1/") {
			t.Fatalf("mail.smtp-imap.v1's own empty root must never appear in the archive, found %q", name)
		}
	}
}

func TestCreateReplayAgainstAnExistingArtifactIsIdempotentAndLeavesItUnchanged(t *testing.T) {
	artifactsRoot := t.TempDir()
	stateRoots, _ := buildFakeStateRoots(t)
	capability := backup.New(backup.Config{ArtifactsRoot: artifactsRoot, StateRoots: stateRoots})

	key := newTestEncryptionKey()
	resourceID := newTestUUID()

	first, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, newTestUUID(),
		map[string]any{"encryption_key": key}))
	requireStatus(t, "first create", first, err, protocol.StatusApplied)

	firstData := decodeArtifactData(t, first.Data)
	firstSealed, err := os.ReadFile(firstData["artifact_path"].(string))
	if err != nil {
		t.Fatalf("reading first artifact: %v", err)
	}

	// A distinct idempotency key forces this second call through the
	// on-disk "does this resource_id's artifact already exist" check
	// instead of the in-process idempotency.Store's own receipt replay.
	second, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, newTestUUID(),
		map[string]any{"encryption_key": newTestEncryptionKey()}))
	requireStatus(t, "second create (idempotent replay)", second, err, protocol.StatusAlreadyApplied)

	secondData := decodeArtifactData(t, second.Data)
	secondSealed, err := os.ReadFile(secondData["artifact_path"].(string))
	if err != nil {
		t.Fatalf("reading second artifact: %v", err)
	}

	if string(firstSealed) != string(secondSealed) {
		t.Fatal("an idempotent replay must never rewrite the existing artifact on disk")
	}
}

func TestDeleteRemovesARealArtifactAndIsIdempotent(t *testing.T) {
	artifactsRoot := t.TempDir()
	stateRoots, _ := buildFakeStateRoots(t)
	capability := backup.New(backup.Config{ArtifactsRoot: artifactsRoot, StateRoots: stateRoots})

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(),
		map[string]any{"encryption_key": newTestEncryptionKey()}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	artifactPath := decodeArtifactData(t, created.Data)["artifact_path"].(string)

	deleted, err := capability.Apply(context.Background(), newOp(protocol.OperationDelete, newTestUUID(), newTestUUID(),
		map[string]any{"artifact_path": artifactPath}))
	requireStatus(t, "delete", deleted, err, protocol.StatusApplied)

	if _, statErr := os.Stat(artifactPath); !os.IsNotExist(statErr) {
		t.Fatalf("expected the artifact to be removed from disk, stat error: %v", statErr)
	}

	deletedAgain, err := capability.Apply(context.Background(), newOp(protocol.OperationDelete, newTestUUID(), newTestUUID(),
		map[string]any{"artifact_path": artifactPath}))
	requireStatus(t, "delete again (idempotent)", deletedAgain, err, protocol.StatusAlreadyApplied)
}
