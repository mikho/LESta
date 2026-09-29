package mariadb_test

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/mariadb"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// newSudoStub writes a tiny script standing in for the real "sudo" binary:
// Config.command() invokes it as "<stub> <mariadbBinary> <args...>", so the
// stub's own job is just to drop that leading duplicate binary name and exec
// straight through to the real mariadb client, proving Config.SudoBinary's
// own argv shape is correct end-to-end against a real disposable mariadbd
// instance, without needing this test process to actually run as root.
func newSudoStub(t *testing.T) string {
	t.Helper()

	path := filepath.Join(t.TempDir(), "sudo-stub.sh")
	script := "#!/bin/sh\nshift\nexec mariadb \"$@\"\n"

	if err := os.WriteFile(path, []byte(script), 0o755); err != nil {
		t.Fatalf("writing sudo stub: %v", err)
	}

	return path
}

// TestSudoBinaryRoutesClientInvocationCorrectly proves Config.SudoBinary's
// own wrapping shape (exec.go's runSQL, routed through command()) against a
// real disposable mariadbd: a full create then real connect-as-tenant round
// trip, exactly like the SudoBinary-unset tests elsewhere in this package,
// but with every DDL invocation this capability makes routed through the
// stub instead of invoked directly. Found deploying to a real node:
// production always sets this (DefaultsExtraFile is deliberately
// root-owned 0600, so the unprivileged lesta-agent-daemon cannot open it
// directly), so this is the one branch that was never exercised by any
// other test in this package before now.
func TestSudoBinaryRoutesClientInvocationCorrectly(t *testing.T) {
	requireRealMariaDB(t)

	d := newDisposableMariaDB(t)
	d.Config.SudoBinary = newSudoStub(t)
	capability := mariadb.New(d.Config)
	ctx := context.Background()

	const (
		databaseName = "lesta_1_sudostub"
		databaseUser = "lesta_1_sudostub"
	)

	resourceID := newTestUUID()
	password := randomHex(t, 24)

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, tenantPayload(databaseName, databaseUser, strPtr(password), false)))
	requireApplied(t, "create", created, err)

	out, err := d.connectAsTenant(databaseUser, password, databaseName, "CREATE TABLE t (id INT); INSERT INTO t VALUES (1); SELECT * FROM t;")
	if err != nil {
		t.Fatalf("expected a real connect+query to succeed after a sudo-stub-routed create: %v", err)
	}

	if !strings.Contains(out, "1") {
		t.Fatalf("expected the query output to reflect the inserted row, got %q", out)
	}

	deleted, err := capability.Apply(ctx, newOp(protocol.OperationDelete, resourceID, newTestUUID(), 2, tenantPayload(databaseName, databaseUser, nil, false)))
	requireApplied(t, "delete", deleted, err)
}
