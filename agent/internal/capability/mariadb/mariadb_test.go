package mariadb_test

import (
	"context"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/mariadb"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// TestMariaDBCapability_FullLifecycle exercises every database.tenant.v1
// verb against one real, disposable tenant MariaDB instance, proving each
// step against a real client connection -- never merely internal state.
// Subtests run sequentially (no t.Parallel()) against one shared resource,
// each depending on the previous step's real, on-the-wire effect.
func TestMariaDBCapability_FullLifecycle(t *testing.T) {
	requireRealMariaDB(t)

	d := newDisposableMariaDB(t)
	capability := mariadb.New(d.Config)
	ctx := context.Background()

	const (
		databaseName = "lesta_1_app1"
		databaseUser = "lesta_1_app1"
		statsUser    = "lesta_1_app1_ro"
	)

	resourceID := newTestUUID()
	password1 := randomHex(t, 24)
	statsPassword1 := statsPasswordFor(password1)

	createOp := newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, tenantPayload(databaseName, databaseUser, strPtr(password1), false))

	t.Run("create provisions a real database and user, connectable immediately", func(t *testing.T) {
		result, err := capability.Apply(ctx, createOp)
		requireApplied(t, "create", result, err)

		out, err := d.connectAsTenant(databaseUser, password1, databaseName, "CREATE TABLE t (id INT); INSERT INTO t VALUES (1); SELECT * FROM t;")
		if err != nil {
			t.Fatalf("expected a real connect+query to succeed after create: %v", err)
		}
		if !strings.Contains(out, "1") {
			t.Fatalf("expected the query output to reflect the inserted row, got %q", out)
		}
	})

	t.Run("create also provisions a real least-privilege statistics reader: it can read, but not write", func(t *testing.T) {
		out, err := d.connectAsTenant(statsUser, statsPassword1, databaseName, "SELECT * FROM t;")
		if err != nil {
			t.Fatalf("expected the statistics reader to connect and SELECT immediately after create: %v", err)
		}
		if !strings.Contains(out, "1") {
			t.Fatalf("expected the statistics reader to see the tenant's own row, got %q", out)
		}

		if _, err := d.connectAsTenant(statsUser, statsPassword1, databaseName, "INSERT INTO t VALUES (99);"); err == nil {
			t.Fatal("expected the statistics reader's INSERT to be rejected (SELECT-only grant), but it succeeded")
		}
	})

	t.Run("a replayed create (identical idempotency key) is served from the receipt, not re-applied", func(t *testing.T) {
		replay, err := capability.Apply(ctx, createOp)
		requireStatus(t, "replayed create", replay, err, protocol.StatusAlreadyApplied)
	})

	t.Run("a second create against the same resource with a different idempotency key is rejected", func(t *testing.T) {
		op := newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, tenantPayload(databaseName, databaseUser, strPtr(password1), false))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "duplicate create", result, err, protocol.StatusRejected)
		requireErrorCode(t, "duplicate create", result, "resource_already_exists")
	})

	t.Run("observe reports no drift immediately after create", func(t *testing.T) {
		op := newOp(protocol.OperationObserve, resourceID, newTestUUID(), 1, tenantPayload(databaseName, databaseUser, nil, false))
		result, err := capability.Apply(ctx, op)
		requireApplied(t, "observe with no drift", result, err)
	})

	t.Run("suspend revokes access for both the tenant's own account and the statistics reader", func(t *testing.T) {
		op := newOp(protocol.OperationSuspend, resourceID, newTestUUID(), 2, tenantPayload(databaseName, databaseUser, nil, true))
		result, err := capability.Apply(ctx, op)
		requireApplied(t, "suspend", result, err)

		if _, err := d.connectAsTenant(databaseUser, password1, databaseName, "SELECT 1 FROM t;"); err == nil {
			t.Fatal("expected the tenant's own query to fail after suspend, but it succeeded")
		}

		if _, err := d.connectAsTenant(statsUser, statsPassword1, databaseName, "SELECT 1 FROM t;"); err == nil {
			t.Fatal("expected the statistics reader's query to fail after suspend too, but it succeeded")
		}
	})

	t.Run("unsuspend restores access for both accounts without any password change", func(t *testing.T) {
		op := newOp(protocol.OperationUnsuspend, resourceID, newTestUUID(), 3, tenantPayload(databaseName, databaseUser, nil, false))
		result, err := capability.Apply(ctx, op)
		requireApplied(t, "unsuspend", result, err)

		out, err := d.connectAsTenant(databaseUser, password1, databaseName, "SELECT * FROM t;")
		if err != nil {
			t.Fatalf("expected the same, never-changed password to work again after unsuspend: %v", err)
		}
		if !strings.Contains(out, "1") {
			t.Fatalf("expected the pre-suspend row to still be there, got %q", out)
		}

		out, err = d.connectAsTenant(statsUser, statsPassword1, databaseName, "SELECT * FROM t;")
		if err != nil {
			t.Fatalf("expected the statistics reader's same, never-changed password to work again after unsuspend: %v", err)
		}
		if !strings.Contains(out, "1") {
			t.Fatalf("expected the statistics reader to see the pre-suspend row again, got %q", out)
		}
	})

	password2 := randomHex(t, 24)
	statsPassword2 := statsPasswordFor(password2)

	t.Run("rotate changes both accounts' passwords in lockstep: old passwords fail, new succeed, and existing grants survive", func(t *testing.T) {
		op := newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 4, tenantPayload(databaseName, databaseUser, strPtr(password2), false))
		result, err := capability.Apply(ctx, op)
		requireApplied(t, "rotate", result, err)

		if _, err := d.connectAsTenant(databaseUser, password1, databaseName, "SELECT 1;"); err == nil {
			t.Fatal("expected the old tenant password to be rejected after rotation, but it was accepted")
		}
		if _, err := d.connectAsTenant(statsUser, statsPassword1, databaseName, "SELECT 1;"); err == nil {
			t.Fatal("expected the old statistics reader password to be rejected after rotation, but it was accepted")
		}

		// The test that would have caught a wrong CREATE OR REPLACE USER
		// implementation: a real INSERT/CREATE TABLE against the
		// pre-existing grant, not just a SELECT.
		out, err := d.connectAsTenant(databaseUser, password2, databaseName, "CREATE TABLE t2 (id INT); INSERT INTO t2 VALUES (2); SELECT * FROM t2;")
		if err != nil {
			t.Fatalf("expected the new tenant password to work AND existing grants to survive rotation: %v", err)
		}
		if !strings.Contains(out, "2") {
			t.Fatalf("expected the newly inserted row, got %q", out)
		}

		out, err = d.connectAsTenant(statsUser, statsPassword2, databaseName, "SELECT * FROM t2;")
		if err != nil {
			t.Fatalf("expected the new statistics reader password to work AND its SELECT grant to survive rotation: %v", err)
		}
		if !strings.Contains(out, "2") {
			t.Fatalf("expected the statistics reader to see the newly inserted row, got %q", out)
		}
	})

	t.Run("observe detects drift after an out-of-band manual REVOKE", func(t *testing.T) {
		if _, err := d.adminSQL("REVOKE ALL PRIVILEGES, GRANT OPTION FROM '" + databaseUser + "'@'127.0.0.1';\nFLUSH PRIVILEGES;\n"); err != nil {
			t.Fatalf("issuing the out-of-band REVOKE: %v", err)
		}

		op := newOp(protocol.OperationObserve, resourceID, newTestUUID(), 4, tenantPayload(databaseName, databaseUser, nil, false))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "observe after drift", result, err, protocol.StatusDegraded)
		requireErrorCode(t, "observe after drift", result, "drift_detected")
	})

	t.Run("delete removes the schema and both users, regardless of the drifted grant state", func(t *testing.T) {
		op := newOp(protocol.OperationDelete, resourceID, newTestUUID(), 5, tenantPayload(databaseName, databaseUser, nil, false))
		result, err := capability.Apply(ctx, op)
		requireApplied(t, "delete", result, err)

		if _, err := d.connectAsTenant(databaseUser, password2, databaseName, "SELECT 1;"); err == nil {
			t.Fatal("expected the tenant user to be gone after delete, but it could still authenticate")
		}
		if _, err := d.connectAsTenant(statsUser, statsPassword2, databaseName, "SELECT 1;"); err == nil {
			t.Fatal("expected the statistics reader to be gone after delete, but it could still authenticate")
		}

		out, err := d.adminSQL("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '" + databaseName + "';\n")
		if err != nil {
			t.Fatalf("checking schema existence after delete: %v", err)
		}
		if strings.TrimSpace(out) != "0" {
			t.Fatalf("expected the schema to be gone after delete, information_schema still reports %q", out)
		}
	})
}

// TestMariaDBCapability_UnknownResourceRejections proves update/suspend/
// unsuspend/delete/observe against a resource_id this node has no
// generation history for are all rejected outright, never attempting a DDL
// statement at all.
func TestMariaDBCapability_UnknownResourceRejections(t *testing.T) {
	requireRealMariaDB(t)

	d := newDisposableMariaDB(t)
	capability := mariadb.New(d.Config)
	ctx := context.Background()

	const (
		databaseName = "lesta_2_neverexisted"
		databaseUser = "lesta_2_neverexisted"
	)

	cases := []struct {
		name    string
		op      protocol.Operation
		payload map[string]any
	}{
		{"update", protocol.OperationUpdate, tenantPayload(databaseName, databaseUser, strPtr(randomHexStatic()), false)},
		{"suspend", protocol.OperationSuspend, tenantPayload(databaseName, databaseUser, nil, true)},
		{"unsuspend", protocol.OperationUnsuspend, tenantPayload(databaseName, databaseUser, nil, false)},
		{"delete", protocol.OperationDelete, tenantPayload(databaseName, databaseUser, nil, false)},
		{"observe", protocol.OperationObserve, tenantPayload(databaseName, databaseUser, nil, false)},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			op := newOp(tc.op, newTestUUID(), newTestUUID(), 1, tc.payload)
			result, err := capability.Apply(ctx, op)
			requireStatus(t, tc.name, result, err, protocol.StatusRejected)
			requireErrorCode(t, tc.name, result, "unknown_resource")
		})
	}
}

// TestMariaDBCapability_PayloadValidationRejections proves every malformed
// or verb-inconsistent payload shape is rejected with a specific error code,
// never a bare Go error and never a DDL statement.
func TestMariaDBCapability_PayloadValidationRejections(t *testing.T) {
	requireRealMariaDB(t)

	d := newDisposableMariaDB(t)
	capability := mariadb.New(d.Config)
	ctx := context.Background()

	t.Run("invalid database_name shape is rejected", func(t *testing.T) {
		op := newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, tenantPayload("not-a-valid-name", "not-a-valid-name", strPtr(randomHexStatic()), false))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "invalid database_name", result, err, protocol.StatusRejected)
		requireErrorCode(t, "invalid database_name", result, "invalid_database_name")
	})

	t.Run("database_user not matching database_name is rejected", func(t *testing.T) {
		op := newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, tenantPayload("lesta_1_app1", "lesta_1_app2", strPtr(randomHexStatic()), false))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "database_user mismatch", result, err, protocol.StatusRejected)
		requireErrorCode(t, "database_user mismatch", result, "database_user_mismatch")
	})

	t.Run("malformed password shape is rejected", func(t *testing.T) {
		op := newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, tenantPayload("lesta_1_app1", "lesta_1_app1", strPtr("not-48-hex-chars"), false))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "malformed password", result, err, protocol.StatusRejected)
		requireErrorCode(t, "malformed password", result, "invalid_password")
	})

	t.Run("create without a password is rejected", func(t *testing.T) {
		op := newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, tenantPayload("lesta_1_app1", "lesta_1_app1", nil, false))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "create without password", result, err, protocol.StatusRejected)
		requireErrorCode(t, "create without password", result, "password_required")
	})

	t.Run("invalid stats_user shape is rejected", func(t *testing.T) {
		payload := tenantPayload("lesta_1_app1", "lesta_1_app1", strPtr(randomHexStatic()), false)
		payload["stats_user"] = "not-a-valid-name"
		op := newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, payload)
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "invalid stats_user", result, err, protocol.StatusRejected)
		requireErrorCode(t, "invalid stats_user", result, "invalid_stats_user")
	})

	t.Run("stats_user not matching database_user plus _ro is rejected", func(t *testing.T) {
		payload := tenantPayload("lesta_1_app1", "lesta_1_app1", strPtr(randomHexStatic()), false)
		payload["stats_user"] = "lesta_1_app2_ro"
		op := newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, payload)
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "stats_user mismatch", result, err, protocol.StatusRejected)
		requireErrorCode(t, "stats_user mismatch", result, "stats_user_mismatch")
	})

	t.Run("create with a password but no stats_password is rejected", func(t *testing.T) {
		payload := tenantPayload("lesta_1_app1", "lesta_1_app1", strPtr(randomHexStatic()), false)
		delete(payload, "stats_password")
		op := newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, payload)
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "create without stats_password", result, err, protocol.StatusRejected)
		requireErrorCode(t, "create without stats_password", result, "stats_password_required")
	})

	t.Run("suspend carrying a stats_password is rejected", func(t *testing.T) {
		payload := tenantPayload("lesta_1_app1", "lesta_1_app1", nil, true)
		payload["stats_password"] = randomHexStatic()
		op := newOp(protocol.OperationSuspend, newTestUUID(), newTestUUID(), 1, payload)
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "suspend with stats_password", result, err, protocol.StatusRejected)
		requireErrorCode(t, "suspend with stats_password", result, "stats_password_not_allowed")
	})

	t.Run("suspend carrying a password is rejected", func(t *testing.T) {
		op := newOp(protocol.OperationSuspend, newTestUUID(), newTestUUID(), 1, tenantPayload("lesta_1_app1", "lesta_1_app1", strPtr(randomHexStatic()), true))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "suspend with password", result, err, protocol.StatusRejected)
		requireErrorCode(t, "suspend with password", result, "password_not_allowed")
	})

	t.Run("delete carrying a password is rejected", func(t *testing.T) {
		op := newOp(protocol.OperationDelete, newTestUUID(), newTestUUID(), 1, tenantPayload("lesta_1_app1", "lesta_1_app1", strPtr(randomHexStatic()), false))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "delete with password", result, err, protocol.StatusRejected)
		requireErrorCode(t, "delete with password", result, "password_not_allowed")
	})

	t.Run("suspend with payload.suspended=false is rejected", func(t *testing.T) {
		op := newOp(protocol.OperationSuspend, newTestUUID(), newTestUUID(), 1, tenantPayload("lesta_1_app1", "lesta_1_app1", nil, false))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "suspend with suspended=false", result, err, protocol.StatusRejected)
		requireErrorCode(t, "suspend with suspended=false", result, "suspended_mismatch")
	})

	t.Run("unsuspend with payload.suspended=true is rejected", func(t *testing.T) {
		op := newOp(protocol.OperationUnsuspend, newTestUUID(), newTestUUID(), 1, tenantPayload("lesta_1_app1", "lesta_1_app1", nil, true))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "unsuspend with suspended=true", result, err, protocol.StatusRejected)
		requireErrorCode(t, "unsuspend with suspended=true", result, "suspended_mismatch")
	})

	t.Run("an unsupported operation is rejected", func(t *testing.T) {
		op := newOp(protocol.Operation("bogus"), newTestUUID(), newTestUUID(), 1, tenantPayload("lesta_1_app1", "lesta_1_app1", nil, false))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "unsupported operation", result, err, protocol.StatusRejected)
		requireErrorCode(t, "unsupported operation", result, "unsupported_operation")
	})
}

// randomHexStatic returns a fixed-shape, 48-lowercase-hex-character string
// for payload-validation-only tests that need a well-formed password to
// isolate the one field actually under test -- never asserted for
// uniqueness, unlike randomHex(t, ...).
func randomHexStatic() string {
	return strings.Repeat("ab", 24)
}
