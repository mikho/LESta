package identity_test

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/identity"
	"github.com/mikho/LESta/agent/internal/protocol"
)

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
	requireRootAndUseradd(t)
	requireRealSshd(t)

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

// newAgentBinaryStub writes a tiny script standing in for the real
// lesta-agent binary's own "identity-ensure-chroot-tree" CLI mode:
// ensureChrootTreePrivileged invokes it as "<sudo stub> <this stub>
// identity-ensure-chroot-tree <username>", so this stub's own job is to
// perform the same real mkdir/chmod/chown sequence ensureChrootTree itself
// does (chroot.go), against accountsRoot passed in directly rather than
// read from a Config this shell script has no access to. Proves
// ensureChrootTreePrivileged's own argv shape and end-to-end effect
// (real ownership, not just "some command ran") without needing a second
// real Go binary built for this one test.
func newAgentBinaryStub(t *testing.T, accountsRoot string) string {
	t.Helper()

	path := filepath.Join(t.TempDir(), "agent-stub.sh")
	script := fmt.Sprintf(`#!/bin/sh
if [ "$1" != "identity-ensure-chroot-tree" ]; then
    exit 1
fi
username="$2"
root=%q/"$username"
mkdir -p "$root" && chmod 0755 "$root" && chown root:root "$root" || exit 1
mkdir -p "$root/public" && chmod 0750 "$root/public" && chown "$username:$username" "$root/public" || exit 1
`, accountsRoot)

	if err := os.WriteFile(path, []byte(script), 0o755); err != nil {
		t.Fatalf("writing agent-binary stub: %v", err)
	}

	return path
}

// TestSudoBinaryRoutesChrootTreeCreationCorrectly proves Config.SudoBinary/
// AgentBinaryPath's own wrapping shape for ensureChrootTreePrivileged
// (privileged.go): a real useradd (needs real root, hence
// requireRootAndUseradd), then routing the chroot tree's own mkdir/chown
// sequence entirely through a stubbed sudo+agent-binary pair, and
// confirming the resulting real directories exist with the exact
// ownership/mode ensureChrootTree itself would set. Found deploying to a
// real node: production always sets both fields (ensureChrootTree's own
// os.Chown calls require real root, which sudo cannot grant to an
// in-process Go syscall the way it can a separate exec.Command
// invocation), so this is the one branch that was never exercised by any
// other test in this package before now.
func TestSudoBinaryRoutesChrootTreeCreationCorrectly(t *testing.T) {
	requireRootAndUseradd(t)
	requireRealSshd(t)

	sshd := newDisposableSshd(t)
	cfg := sshd.Config
	cfg.SudoBinary = newSudoStub(t)
	cfg.AgentBinaryPath = newAgentBinaryStub(t, cfg.AccountsRoot)
	capability := identity.New(cfg)
	ctx := context.Background()

	resourceID := newTestUUID()
	username := "lestachroot" + newTestUUID()[:8]

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(),
		map[string]any{"username": username}))
	requireStatus(t, "create with sudo-stub-routed chroot tree creation", created, err, protocol.StatusApplied)
	t.Cleanup(func() { _ = exec.Command("userdel", username).Run() })

	publicDir := filepath.Join(cfg.AccountsRoot, username, "public")

	info, err := os.Stat(publicDir)
	if err != nil {
		t.Fatalf("expected a real public dir to exist after a sudo-stub-routed chroot tree creation: %v", err)
	}

	if info.Mode().Perm() != 0o750 {
		t.Fatalf("expected the public dir's own mode to be 0750, got %o", info.Mode().Perm())
	}
}
