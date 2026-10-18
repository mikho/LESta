package mariadb_test

import (
	"context"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/mariadb"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// TestMariaDBCapability_ExtraUsersLifecycle proves extra database users
// against a real, disposable tenant MariaDB instance: each access level's
// real grant, an access change and a password change that keep the other
// half intact, suspension, removal, and the database's delete taking every
// extra user with it. Subtests run in order against one resource.
func TestMariaDBCapability_ExtraUsersLifecycle(t *testing.T) {
	requireRealMariaDB(t)

	d := newDisposableMariaDB(t)
	capability := mariadb.New(d.Config)
	ctx := context.Background()

	const (
		databaseName = "lesta_1_shop"
		reportUser   = "lesta_1_shop_report"
		adminUser    = "lesta_1_shop_admin"
	)

	resourceID := newTestUUID()
	version := 1
	apply := func(t *testing.T, label string, operation protocol.Operation, payload map[string]any) protocol.ResultEnvelope {
		t.Helper()
		version++
		result, err := capability.Apply(ctx, newOp(operation, resourceID, newTestUUID(), version, payload))
		if err != nil {
			t.Fatalf("%s: %v", label, err)
		}

		return result
	}
	withUsers := func(payload map[string]any, users ...map[string]any) map[string]any {
		payload["users"] = users
		return payload
	}
	user := func(name, access string, password *string) map[string]any {
		u := map[string]any{"name": name, "access": access}
		if password != nil {
			u["password"] = *password
		}
		return u
	}

	result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, tenantPayload(databaseName, databaseName, strPtr(randomHex(t, 24)), false)))
	requireApplied(t, "create", result, err)
	if _, err := d.adminSQL("CREATE TABLE lesta_1_shop.orders (id INT); INSERT INTO lesta_1_shop.orders VALUES (1);"); err != nil {
		t.Fatal(err)
	}

	reportPassword := randomHex(t, 24)
	adminPassword := randomHex(t, 24)

	t.Run("an update adds a read-only and a full user, each with its real grant", func(t *testing.T) {
		result := apply(t, "add users", protocol.OperationUpdate, withUsers(tenantPayload(databaseName, databaseName, nil, false),
			user(reportUser, "read_only", &reportPassword), user(adminUser, "full", &adminPassword)))
		requireApplied(t, "add users", result, nil)

		if out, err := d.connectAsTenant(reportUser, reportPassword, databaseName, "SELECT * FROM orders;"); err != nil || !strings.Contains(out, "1") {
			t.Fatalf("the read-only user should read: %q %v", out, err)
		}
		if _, err := d.connectAsTenant(reportUser, reportPassword, databaseName, "INSERT INTO orders VALUES (2);"); err == nil {
			t.Fatal("the read-only user should not write")
		}
		if _, err := d.connectAsTenant(adminUser, adminPassword, databaseName, "INSERT INTO orders VALUES (2);"); err != nil {
			t.Fatalf("the full user should write: %v", err)
		}
	})

	t.Run("observe with the users reports no drift", func(t *testing.T) {
		op := newOp(protocol.OperationObserve, resourceID, newTestUUID(), version, withUsers(tenantPayload(databaseName, databaseName, nil, false),
			user(reportUser, "read_only", nil), user(adminUser, "full", nil)))
		result, err := capability.Apply(ctx, op)
		requireApplied(t, "observe", result, err)
	})

	t.Run("an access change re-grants without touching the password", func(t *testing.T) {
		result := apply(t, "access change", protocol.OperationUpdate, withUsers(tenantPayload(databaseName, databaseName, nil, false),
			user(reportUser, "full", nil), user(adminUser, "full", nil)))
		requireApplied(t, "access change", result, nil)

		if _, err := d.connectAsTenant(reportUser, reportPassword, databaseName, "INSERT INTO orders VALUES (3);"); err != nil {
			t.Fatalf("the user should now write, with the same password: %v", err)
		}
	})

	newAdminPassword := randomHex(t, 24)

	t.Run("a password change keeps the grant", func(t *testing.T) {
		result := apply(t, "password change", protocol.OperationUpdate, withUsers(tenantPayload(databaseName, databaseName, nil, false),
			user(reportUser, "full", nil), user(adminUser, "full", &newAdminPassword)))
		requireApplied(t, "password change", result, nil)

		if _, err := d.connectAsTenant(adminUser, adminPassword, databaseName, "SELECT 1;"); err == nil {
			t.Fatal("the old password should no longer work")
		}
		if _, err := d.connectAsTenant(adminUser, newAdminPassword, databaseName, "INSERT INTO orders VALUES (4);"); err != nil {
			t.Fatalf("the new password should work with the same grant: %v", err)
		}
	})

	t.Run("a new user without a password is refused", func(t *testing.T) {
		result := apply(t, "new user without password", protocol.OperationUpdate, withUsers(tenantPayload(databaseName, databaseName, nil, false),
			user(reportUser, "full", nil), user(adminUser, "full", nil), user("lesta_1_shop_extra", "full", nil)))
		requireStatus(t, "new user without password", result, nil, protocol.StatusRejected)
		requireErrorCode(t, "new user without password", result, "user_password_required")
	})

	t.Run("suspend takes the users' access and unsuspend gives it back", func(t *testing.T) {
		result := apply(t, "suspend", protocol.OperationSuspend, withUsers(tenantPayload(databaseName, databaseName, nil, true),
			user(reportUser, "full", nil), user(adminUser, "full", nil)))
		requireApplied(t, "suspend", result, nil)
		if _, err := d.connectAsTenant(reportUser, reportPassword, databaseName, "SELECT * FROM orders;"); err == nil {
			t.Fatal("a suspended database's extra user should not read")
		}

		result = apply(t, "unsuspend", protocol.OperationUnsuspend, withUsers(tenantPayload(databaseName, databaseName, nil, false),
			user(reportUser, "full", nil), user(adminUser, "full", nil)))
		requireApplied(t, "unsuspend", result, nil)
		if _, err := d.connectAsTenant(reportUser, reportPassword, databaseName, "SELECT * FROM orders;"); err != nil {
			t.Fatalf("after unsuspend the extra user should read again: %v", err)
		}
	})

	t.Run("a user left out of an update is dropped", func(t *testing.T) {
		result := apply(t, "remove user", protocol.OperationUpdate, withUsers(tenantPayload(databaseName, databaseName, nil, false),
			user(reportUser, "full", nil)))
		requireApplied(t, "remove user", result, nil)

		if out, _ := d.adminSQL("SELECT COUNT(*) FROM mysql.user WHERE User = '" + adminUser + "';"); strings.TrimSpace(out) != "0" {
			t.Fatalf("the removed user should be gone, count %q", out)
		}
	})

	t.Run("deleting the database drops every extra user the node knows of", func(t *testing.T) {
		result := apply(t, "delete", protocol.OperationDelete, tenantPayload(databaseName, databaseName, nil, false))
		requireApplied(t, "delete", result, nil)

		if out, _ := d.adminSQL("SELECT COUNT(*) FROM mysql.user WHERE User LIKE 'lesta\\_1\\_shop%';"); strings.TrimSpace(out) != "0" {
			t.Fatalf("every user of the database should be gone, count %q", out)
		}
	})
}

// TestMariaDBCapability_ExtraUserPayloadRejections checks the agent's own
// validation of extra users, independent of the panel's.
func TestMariaDBCapability_ExtraUserPayloadRejections(t *testing.T) {
	requireRealMariaDB(t)

	capability := mariadb.New(newDisposableMariaDB(t).Config)
	password := randomHex(t, 24)

	cases := []struct {
		name  string
		users []map[string]any
		code  string
	}{
		{"another database's prefix", []map[string]any{{"name": "lesta_2_shop_report", "access": "full", "password": password}}, "invalid_user_name"},
		{"the statistics account's name", []map[string]any{{"name": "lesta_1_shop_ro", "access": "full", "password": password}}, "invalid_user_name"},
		{"a quote in the name", []map[string]any{{"name": "lesta_1_shop_x'", "access": "full", "password": password}}, "invalid_user_name"},
		{"an unknown access level", []map[string]any{{"name": "lesta_1_shop_report", "access": "owner", "password": password}}, "invalid_user_access"},
		{"a malformed password", []map[string]any{{"name": "lesta_1_shop_report", "access": "full", "password": "secret"}}, "invalid_user_password"},
		{"the same user twice", []map[string]any{{"name": "lesta_1_shop_report", "access": "full", "password": password}, {"name": "lesta_1_shop_report", "access": "full", "password": password}}, "duplicate_user"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			payload := tenantPayload("lesta_1_shop", "lesta_1_shop", strPtr(password), false)
			payload["users"] = tc.users
			result, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, payload))
			requireStatus(t, tc.name, result, err, protocol.StatusRejected)
			requireErrorCode(t, tc.name, result, tc.code)
		})
	}

	t.Run("a suspend may not carry an extra user's password", func(t *testing.T) {
		payload := tenantPayload("lesta_1_shop", "lesta_1_shop", nil, true)
		payload["users"] = []map[string]any{{"name": "lesta_1_shop_report", "access": "full", "password": password}}
		result, err := capability.Apply(context.Background(), newOp(protocol.OperationSuspend, newTestUUID(), newTestUUID(), 2, payload))
		requireStatus(t, "suspend with password", result, err, protocol.StatusRejected)
		requireErrorCode(t, "suspend with password", result, "user_password_not_allowed")
	})
}
