package identity_test

import (
	"context"
	"os"
	"os/exec"
	"path/filepath"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/identity"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// requireRealUseradd skips the calling test, with a clear reason, if
// useradd/userdel aren't on PATH: unlike sshd (which macOS ships its own
// Apple-built copy of), useradd/userdel are Linux-only GNU shadow-utils
// tools with no macOS equivalent at all -- this is a real, structurally
// undetectable-on-this-Mac gap (see the vault's Remote Verification
// Checklist's own AppArmor/systemd/apt entries for the same class of gap),
// not a bug this test could work around.
func requireRealUseradd(t *testing.T) {
	t.Helper()

	for _, bin := range []string{"useradd", "userdel"} {
		if _, err := exec.LookPath(bin); err != nil {
			t.Skipf("%s is not installed on PATH; skipping the real sudo-routed useradd/userdel suite", bin)
		}
	}
}

// newSudoStub writes a tiny script standing in for the real "sudo" binary:
// Config.command() invokes it as "<stub> <resolved binary> <args...>", so
// the stub's own job is just to exec straight through to that resolved
// binary (already $1), proving Config.SudoBinary's own argv shape is
// correct end-to-end against a real disposable sshd instance, without
// needing this test process to actually run as root.
func newSudoStub(t *testing.T) string {
	t.Helper()

	path := filepath.Join(t.TempDir(), "sudo-stub.sh")
	script := "#!/bin/sh\nexec \"$@\"\n"

	if err := os.WriteFile(path, []byte(script), 0o755); err != nil {
		t.Fatalf("writing sudo stub: %v", err)
	}

	return path
}

// TestSudoBinaryRoutesUseraddAndUserdelCorrectly proves Config.SudoBinary's
// own wrapping shape (exec.go's createSystemUser, deleteSystemUser) against
// a real disposable sshd: a full create-then-delete round trip, exactly
// like TestCreateAndDeleteRoundTripAgainstARealSystemUser, but with every
// useradd/userdel invocation this capability makes routed through the stub
// instead of invoked directly. Found deploying to a real node: production
// always sets this (useradd/userdel always require root; there is no
// file-permission fix for the unprivileged lesta-agent-daemon), so this is
// the one branch that was never exercised by any other test in this
// package before now.
func TestSudoBinaryRoutesUseraddAndUserdelCorrectly(t *testing.T) {
	requireRealSshd(t)
	requireRealUseradd(t)

	sshd := newDisposableSshd(t)
	cfg := sshd.Config
	cfg.SudoBinary = newSudoStub(t)
	capability := identity.New(cfg)
	ctx := context.Background()

	resourceID := newTestUUID()
	username := "lestasudo" + newTestUUID()[:8]

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(),
		map[string]any{"username": username}))
	requireStatus(t, "create via sudo stub", created, err, protocol.StatusApplied)

	if _, err := os.Stat(filepath.Join(cfg.AccountsRoot, username, "public")); err != nil {
		t.Fatalf("expected a real chroot public dir to exist after a sudo-stub-routed create: %v", err)
	}

	deleted, err := capability.Apply(ctx, newOp(protocol.OperationDelete, resourceID, newTestUUID(),
		map[string]any{"username": username}))
	requireStatus(t, "delete via sudo stub", deleted, err, protocol.StatusApplied)
}
