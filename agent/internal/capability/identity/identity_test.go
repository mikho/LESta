package identity_test

import (
	"context"
	"crypto/rand"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/identity"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// requireRootAndUseradd skips the calling test, with a clear reason, unless
// this process runs as root AND useradd/userdel/id are all on PATH: a real
// create/delete round trip genuinely mutates /etc/passwd, which is only
// possible as root, mirroring internal/capability/mariadb's own
// requireRealMariaDB and internal/capability/bind9's own requireRealBind9 --
// this module's established pattern for a test that needs a real,
// privileged external capability the local dev/CI environment may not have.
func requireRootAndUseradd(t *testing.T) {
	t.Helper()

	if os.Geteuid() != 0 {
		t.Skip("not running as root; skipping the real useradd/userdel contract tests")
	}

	for _, bin := range []string{"useradd", "userdel", "id"} {
		if _, err := exec.LookPath(bin); err != nil {
			t.Skipf("%s is not installed on PATH; skipping the real useradd/userdel contract tests", bin)
		}
	}
}

// requireRealSftpClientAndKeygen skips the calling test unless the real
// sftp and ssh-keygen client binaries are on PATH -- distinct from
// requireRealSshd (the server side): this is what actually proves a live
// authenticated session, not just that the capability's own render/
// validate/reload pipeline runs, per Web Application Hosting Threat Model
// and Isolation Design.md's own "sshd -t only checks syntax, never a real
// connection" disclosed limitation.
func requireRealSftpClientAndKeygen(t *testing.T) {
	t.Helper()

	for _, bin := range []string{"sftp", "ssh-keygen"} {
		if _, err := exec.LookPath(bin); err != nil {
			t.Skipf("%s is not installed on PATH; skipping the real authenticated-SFTP-session contract test", bin)
		}
	}
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

func newOp(operation protocol.Operation, resourceID, idempotencyKey string, payload map[string]any) protocol.OperationEnvelope {
	raw, err := json.Marshal(payload)
	if err != nil {
		panic(fmt.Sprintf("marshaling test payload: %v", err))
	}

	now := time.Now().UTC()

	return protocol.OperationEnvelope{
		ProtocolVersion:     "1",
		Capability:          "system.account-identity.v1",
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

func TestApplyRejectsAnEmptyUsername(t *testing.T) {
	capability := identity.New(identity.Config{})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(),
		map[string]any{"username": ""}))
	requireStatus(t, "create with empty username", result, err, protocol.StatusRejected)
	requireErrorCode(t, "create with empty username", result, "invalid_username")
}

func TestApplyRejectsAUsernameWithAnUppercaseLetter(t *testing.T) {
	capability := identity.New(identity.Config{})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(),
		map[string]any{"username": "Lesta-t42"}))
	requireStatus(t, "create with an uppercase username", result, err, protocol.StatusRejected)
	requireErrorCode(t, "create with an uppercase username", result, "invalid_username")
}

func TestApplyRejectsAUsernameContainingAPathSeparator(t *testing.T) {
	capability := identity.New(identity.Config{})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(),
		map[string]any{"username": "../../etc/passwd"}))
	requireStatus(t, "create with a path-traversal username", result, err, protocol.StatusRejected)
	requireErrorCode(t, "create with a path-traversal username", result, "invalid_username")
}

func TestApplyRejectsAnUnsupportedOperation(t *testing.T) {
	capability := identity.New(identity.Config{})

	// Observe is real and supported since step 2 (Web Application Hosting
	// Threat Model and Isolation Design.md); suspend/unsuspend remain the
	// genuinely unsupported operations (see this package's own doc comment
	// on why).
	result, err := capability.Apply(context.Background(), newOp(protocol.OperationSuspend, newTestUUID(), newTestUUID(),
		map[string]any{"username": "lesta-t42"}))
	requireStatus(t, "suspend", result, err, protocol.StatusRejected)
	requireErrorCode(t, "suspend", result, "unsupported_operation")
}

func TestCreateAndDeleteRoundTripAgainstARealSystemUser(t *testing.T) {
	requireRootAndUseradd(t)
	requireRealSshd(t)

	sshd := newDisposableSshd(t)
	capability := identity.New(sshd.reloadConfig())
	resourceID := newTestUUID()
	username := "lestatest" + strings.ReplaceAll(newTestUUID(), "-", "")[:8]
	createKey := newTestUUID()

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, createKey,
		map[string]any{"username": username}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	t.Cleanup(func() {
		_ = exec.Command("userdel", username).Run()
	})

	if _, err := os.Stat(filepath.Join(sshd.Config.AccountsRoot, username, "public")); err != nil {
		t.Fatalf("create did not set up the real chroot tree: %v", err)
	}

	// A replay of the same operation (same idempotency key); a create with a
	// new key would be resource_already_exists, as in every capability.
	createdAgain, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, createKey,
		map[string]any{"username": username}))
	requireStatus(t, "create again (idempotent)", createdAgain, err, protocol.StatusAlreadyApplied)

	observedAfterCreate, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, resourceID, newTestUUID(),
		map[string]any{"username": username}))
	requireStatus(t, "observe after create", observedAfterCreate, err, protocol.StatusApplied)

	key := "ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIA9uWMFvUZWUPFZhLxwGh8VXPI2ovBsPoAX4pDIzoIXY test@example.com"

	updated, err := capability.Apply(context.Background(), newOp(protocol.OperationUpdate, resourceID, newTestUUID(),
		map[string]any{"username": username, "ssh_public_key": key}))
	requireStatus(t, "update with a real key", updated, err, protocol.StatusApplied)

	authorizedKeysContent, err := os.ReadFile(filepath.Join(sshd.Config.AuthorizedKeysDir, username))
	if err != nil {
		t.Fatalf("reading authorized_keys after update: %v", err)
	}
	if strings.TrimSpace(string(authorizedKeysContent)) != key {
		t.Fatalf("authorized_keys content = %q, want %q", authorizedKeysContent, key)
	}

	clearedKey, err := capability.Apply(context.Background(), newOp(protocol.OperationUpdate, resourceID, newTestUUID(),
		map[string]any{"username": username, "ssh_public_key": nil}))
	requireStatus(t, "update clearing the key", clearedKey, err, protocol.StatusApplied)

	clearedContent, err := os.ReadFile(filepath.Join(sshd.Config.AuthorizedKeysDir, username))
	if err != nil {
		t.Fatalf("reading authorized_keys after clearing: %v", err)
	}
	if len(clearedContent) != 0 {
		t.Fatalf("authorized_keys content after clearing = %q, want empty", clearedContent)
	}

	deleted, err := capability.Apply(context.Background(), newOp(protocol.OperationDelete, resourceID, newTestUUID(),
		map[string]any{"username": username}))
	requireStatus(t, "delete", deleted, err, protocol.StatusApplied)

	if _, err := os.Stat(filepath.Join(sshd.Config.SftpConfigDir, resourceID+".conf")); !os.IsNotExist(err) {
		t.Fatalf("expected the live Match block to be removed after delete, stat error: %v", err)
	}

	deletedAgain, err := capability.Apply(context.Background(), newOp(protocol.OperationDelete, resourceID, newTestUUID(),
		map[string]any{"username": username}))
	requireStatus(t, "delete again (idempotent)", deletedAgain, err, protocol.StatusApplied)
}

// TestRealAuthenticatedSftpSessionUploadsDownloadsAndCannotEscapeChroot is
// the one proof this package's own harness_test.go doc comment explicitly
// disclaims: `sshd -t` never checks that ChrootDirectory's own real
// ownership requirement holds, only that the config is syntactically
// valid, so nothing else in this suite proves a live, authenticated
// session actually succeeds, stays confined, and behaves the way a real
// tenant's own SFTP client would experience it. Requires real root (for
// useradd and the chroot's own root-owned tree) and the real sftp/
// ssh-keygen client binaries -- skips everywhere neither holds, including
// this Mac.
func TestRealAuthenticatedSftpSessionUploadsDownloadsAndCannotEscapeChroot(t *testing.T) {
	requireRootAndUseradd(t)
	requireRealSshd(t)
	requireRealSftpClientAndKeygen(t)

	sshd := newDisposableSshd(t)
	capability := identity.New(sshd.reloadConfig())
	resourceID := newTestUUID()
	username := "lestatest" + strings.ReplaceAll(newTestUUID(), "-", "")[:8]

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, newTestUUID(),
		map[string]any{"username": username}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	t.Cleanup(func() {
		_ = exec.Command("userdel", username).Run()
	})

	keyDir := t.TempDir()
	privateKeyPath := filepath.Join(keyDir, "id_ed25519")

	keygen := exec.Command("ssh-keygen", "-q", "-N", "", "-t", "ed25519", "-f", privateKeyPath)
	if out, err := keygen.CombinedOutput(); err != nil {
		t.Fatalf("generating a real client keypair: %v: %s", err, out)
	}

	publicKey, err := os.ReadFile(privateKeyPath + ".pub")
	if err != nil {
		t.Fatalf("reading the generated public key: %v", err)
	}

	updated, err := capability.Apply(context.Background(), newOp(protocol.OperationUpdate, resourceID, newTestUUID(),
		map[string]any{"username": username, "ssh_public_key": strings.TrimSpace(string(publicKey))}))
	requireStatus(t, "update with the real client key", updated, err, protocol.StatusApplied)

	// A local file to round-trip through the real session, plus a distinct
	// second file placed directly on this host's own real filesystem
	// outside the chroot entirely, at the exact relative path a broken
	// chroot would let the session see straight through to.
	localUpload := filepath.Join(keyDir, "upload.txt")
	uploadContent := "lesta sftp real round trip " + resourceID
	if err := os.WriteFile(localUpload, []byte(uploadContent), 0o644); err != nil {
		t.Fatalf("writing the local file to upload: %v", err)
	}

	outsideMarkerPath := filepath.Join(t.TempDir(), "outside-marker.txt")
	if err := os.WriteFile(outsideMarkerPath, []byte("this file must never be reachable from inside the chroot"), 0o644); err != nil {
		t.Fatalf("writing the outside-chroot marker file: %v", err)
	}

	localDownload := filepath.Join(keyDir, "download.txt")

	escapedDownload := filepath.Join(keyDir, "escaped.txt")

	batch := fmt.Sprintf("put %s roundtrip.txt\nget roundtrip.txt %s\nget %s %s\n", localUpload, localDownload, outsideMarkerPath, escapedDownload)
	batchPath := filepath.Join(keyDir, "batch.txt")
	if err := os.WriteFile(batchPath, []byte(batch), 0o644); err != nil {
		t.Fatalf("writing the sftp batch file: %v", err)
	}

	sftpCmd := exec.Command("sftp",
		"-b", batchPath,
		"-i", privateKeyPath,
		"-P", strconv.Itoa(sshd.Port),
		"-o", "StrictHostKeyChecking=no",
		"-o", "UserKnownHostsFile=/dev/null",
		fmt.Sprintf("%s@127.0.0.1", username),
	)
	out, sftpErr := sftpCmd.CombinedOutput()

	// The batch's own second `get` (outsideMarkerPath, a real, existing
	// file on this host) MUST fail: inside a real chroot, that absolute
	// path resolves to AccountsRoot/<username>/tmp/.../outside-marker.txt,
	// which does not exist, so sftp's own batch-mode behavior is to report
	// that one command's failure in its output and exit non-zero for the
	// whole batch -- this is the expected, passing outcome, not a test
	// failure. A batch-mode `sftp` exiting 0 here would mean the chroot
	// escape attempt actually succeeded: a real, serious finding, not a
	// flaky test to retry.
	if sftpErr == nil {
		t.Fatalf("sftp batch exited 0; expected the chroot-escape get to fail. Full output:\n%s", out)
	}
	// Checked by its effect, not sftp's wording: OpenSSH 9.6's client only
	// prints "No such file" for a failed get in verbose mode.
	if _, err := os.Stat(escapedDownload); !os.IsNotExist(err) {
		t.Fatalf("the chroot-escape get wrote %s (stat error: %v); the session could read outside its chroot. Full output:\n%s", escapedDownload, err, out)
	}

	downloaded, err := os.ReadFile(localDownload)
	if err != nil {
		t.Fatalf("the real upload/download round trip (roundtrip.txt) never completed despite the batch's later chroot-escape command failing as expected -- reading %s: %v\nFull sftp output:\n%s", localDownload, err, out)
	}
	if string(downloaded) != uploadContent {
		t.Fatalf("downloaded content = %q, want %q (the real round trip corrupted the file)", downloaded, uploadContent)
	}

	uploadedOnDisk, err := os.ReadFile(filepath.Join(sshd.Config.AccountsRoot, username, "public", "roundtrip.txt"))
	if err != nil {
		t.Fatalf("the uploaded file did not land where ensureChrootTree's own public/ directory should have put it: %v", err)
	}
	if string(uploadedOnDisk) != uploadContent {
		t.Fatalf("the file landed on disk, but with wrong content: got %q, want %q", uploadedOnDisk, uploadContent)
	}
}
