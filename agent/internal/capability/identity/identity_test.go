package identity_test

import (
	"context"
	"crypto/rand"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
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

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, newTestUUID(),
		map[string]any{"username": username}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	t.Cleanup(func() {
		_ = exec.Command("userdel", username).Run()
	})

	if _, err := os.Stat(filepath.Join(sshd.Config.AccountsRoot, username, "public")); err != nil {
		t.Fatalf("create did not set up the real chroot tree: %v", err)
	}

	createdAgain, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, newTestUUID(),
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
