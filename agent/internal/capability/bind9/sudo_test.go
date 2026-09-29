package bind9_test

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/bind9"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// newSudoStub writes a tiny script standing in for the real "sudo" binary:
// Config.command() invokes it as "<stub> <rndcBinary> <args...>", so the
// stub's own job is just to drop that leading duplicate binary name and exec
// straight through to the real rndc, proving Config.SudoBinary's own argv
// shape is correct end-to-end against a real disposable named instance,
// without needing this test process to actually run as root.
func newSudoStub(t *testing.T) string {
	t.Helper()

	path := filepath.Join(t.TempDir(), "sudo-stub.sh")
	script := "#!/bin/sh\nshift\nexec rndc \"$@\"\n"

	if err := os.WriteFile(path, []byte(script), 0o755); err != nil {
		t.Fatalf("writing sudo stub: %v", err)
	}

	return path
}

// TestSudoBinaryRoutesReloadCorrectly proves Config.SudoBinary's own
// wrapping shape (reload.go's "rndc reload", routed through command())
// against a real disposable named: a full create then delete round trip,
// exactly like the SudoBinary-unset tests elsewhere in this package, but
// with the reload invocation routed through the stub instead of invoked
// directly. Found deploying to a real node: production always sets this
// (rndc always needs to read a real, root-protected rndc.key to
// authenticate its own reload request, and lesta-agent is deliberately
// never added to the bind group), so this is the one branch that was never
// exercised by any other test in this package before now.
func TestSudoBinaryRoutesReloadCorrectly(t *testing.T) {
	requireRealBind9(t)

	d := newDisposableBind9(t)
	d.Config.SudoBinary = newSudoStub(t)
	capability := bind9.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "sudo-stub.contract.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, simplePayload(domain, nil, false)))
	if err != nil || created.Status != protocol.StatusApplied {
		t.Fatalf("create: status=%s err=%v errors=%+v", created.Status, err, created.Errors)
	}

	resolver := resolverAt(d.Port)

	txts, err := resolver.LookupTXT(ctx, "_lesta-marker."+domain+".")
	if err != nil {
		t.Fatalf("real DNS query against the sudo-stub-reloaded zone failed: %v", err)
	}

	found := false
	expected := fmt.Sprintf("resource=%s", resourceID)

	for _, r := range txts {
		if strings.Contains(r, expected) {
			found = true
		}
	}

	if !found {
		t.Fatalf("marker TXT record did not contain resource=%s: got %v -- the stub-routed reload never actually took effect", resourceID, txts)
	}

	deleted, err := capability.Apply(ctx, newOp(protocol.OperationDelete, resourceID, newTestUUID(), 1, simplePayload(domain, nil, false)))
	if err != nil || deleted.Status != protocol.StatusApplied {
		t.Fatalf("delete: status=%s err=%v errors=%+v", deleted.Status, err, deleted.Errors)
	}
}
