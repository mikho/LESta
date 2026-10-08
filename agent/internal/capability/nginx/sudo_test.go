package nginx_test

import (
	"context"
	"fmt"
	"net/http"
	"os"
	"path/filepath"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/nginx"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// newSudoStub writes a tiny script standing in for the real "sudo" binary:
// Config.command() invokes it as "<stub> <nginxBinary> <args...>", so the
// stub's own job is just to drop that leading duplicate binary name and exec
// straight through to the real nginx, proving Config.SudoBinary's own argv
// shape is correct end-to-end against a real disposable nginx instance,
// without needing this test process to actually run as root.
func newSudoStub(t *testing.T) string {
	t.Helper()

	path := filepath.Join(t.TempDir(), "sudo-stub.sh")
	script := "#!/bin/sh\nshift\nexec nginx \"$@\"\n"

	if err := os.WriteFile(path, []byte(script), 0o755); err != nil {
		t.Fatalf("writing sudo stub: %v", err)
	}

	return path
}

// TestSudoBinaryRoutesValidateAndReloadCorrectly proves Config.SudoBinary's
// own wrapping shape (validate.go's "-t" and reload.go's "-s reload", both
// routed through command()) against a real disposable nginx: a full create
// then observe then delete round trip, exactly like the SudoBinary-unset
// tests elsewhere in this package, but with every real nginx invocation
// this capability makes routed through the stub instead of invoked
// directly. Found deploying to a real node: production always sets this
// (nginx refuses to open a real, root-protected TLS key otherwise), so this
// is the one branch that was never exercised by any other test in this
// package before now.
func TestSudoBinaryRoutesValidateAndReloadCorrectly(t *testing.T) {
	requireRealNginx(t)

	d := newDisposableNginx(t)
	d.Config.SudoBinary = newSudoStub(t)
	capability := nginx.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "sudo-stub.contract.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, nginxPayload(domain, "127.0.0.1", false)))
	if err != nil || created.Status != protocol.StatusApplied {
		t.Fatalf("create: status=%s err=%v errors=%+v", created.Status, err, created.Errors)
	}

	req, err := http.NewRequest(http.MethodGet, fmt.Sprintf("http://127.0.0.1:%d/__lesta-health__", d.Port), nil)
	if err != nil {
		t.Fatalf("building request: %v", err)
	}
	req.Host = domain

	resp, err := http.DefaultClient.Do(req)
	if err != nil {
		t.Fatalf("real HTTP request to the sudo-stub-reloaded vhost failed: %v", err)
	}
	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode != http.StatusOK {
		t.Fatalf("real HTTP request got status %d, want 200 -- the stub-routed reload never actually took effect", resp.StatusCode)
	}

	observed, err := capability.Apply(ctx, newOp(protocol.OperationObserve, resourceID, newTestUUID(), 1, nil))
	if err != nil || observed.Status != protocol.StatusApplied {
		t.Fatalf("observe: status=%s err=%v errors=%+v", observed.Status, err, observed.Errors)
	}

	deleted, err := capability.Apply(ctx, newOp(protocol.OperationDelete, resourceID, newTestUUID(), 1, nginxPayload(domain, "127.0.0.1", false)))
	if err != nil || deleted.Status != protocol.StatusApplied {
		t.Fatalf("delete: status=%s err=%v errors=%+v", deleted.Status, err, deleted.Errors)
	}
}
