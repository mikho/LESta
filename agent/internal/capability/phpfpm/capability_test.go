package phpfpm

import (
	"context"
	"crypto/rand"
	"encoding/json"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/protocol"
)

func newTestUUID(t *testing.T) string {
	t.Helper()

	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		t.Fatalf("generating test UUID: %v", err)
	}
	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80

	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16])
}

func newOp(operation protocol.Operation, resourceID string, desiredStateVersion int, payload map[string]any) protocol.OperationEnvelope {
	raw, _ := json.Marshal(payload)

	return protocol.OperationEnvelope{
		ProtocolVersion:     "1",
		Capability:          "web.php-fpm.v1",
		Operation:           operation,
		ResourceID:          resourceID,
		DesiredStateVersion: desiredStateVersion,
		IdempotencyKey:      "idem-" + resourceID + "-" + string(operation) + "-" + fmt.Sprint(desiredStateVersion),
		CorrelationID:       "corr-" + resourceID,
		Deadline:            time.Now().Add(30 * time.Second),
		IssuedAt:            time.Now(),
		Payload:             raw,
	}
}

func fpmPayload(accountID int, username, version string) map[string]any {
	return map[string]any{
		"account_id":       accountID,
		"account_username": username,
		"php_version":      version,
		"suspended":        false,
	}
}

func requireApplied(t *testing.T, label string, result protocol.ResultEnvelope, err error) {
	t.Helper()

	if err != nil {
		t.Fatalf("%s: unexpected error: %v", label, err)
	}

	if result.Status != protocol.StatusApplied {
		t.Fatalf("%s: expected status applied, got %s (errors=%+v)", label, result.Status, result.Errors)
	}
}

// TestCreateThenDeleteRoundTripAgainstARealDisposablePhpFpm proves the whole
// pipeline end to end against a real php-fpm binary: create renders,
// validates, activates, and reloads a real pool, whose real socket this
// test dials directly; delete removes it and leaves the daemon itself
// still healthy.
func TestCreateThenDeleteRoundTripAgainstARealDisposablePhpFpm(t *testing.T) {
	binary, version := requireRealPhpFpm(t)
	d := newDisposablePhpFpm(t, binary, version)

	cfg := d.Config
	capability := New(cfg)
	ctx := context.Background()

	resourceID := newTestUUID(t)
	accountID := 1

	// The pool's own open_basedir points at a real per-domain docroot
	// this test creates directly (matching the real production layout
	// AccountsRoot/<account_id>/domains/<resource_id>/public), even
	// though php-fpm -t never actually opens it -- open_basedir is only
	// enforced at real script execution time, not at config-test time.
	docroot := cfg.docroot(fmt.Sprint(accountID), resourceID)
	if err := os.MkdirAll(docroot, 0o755); err != nil {
		t.Fatalf("creating real docroot: %v", err)
	}

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, 1, fpmPayload(accountID, d.Username, version)))
	requireApplied(t, "create", created, err)

	socketPath := cfg.socketPath(version, resourceID)
	if err := dialUnixSocket(socketPath); err != nil {
		t.Fatalf("expected the real pool socket to accept a connection after create: %v", err)
	}

	poolFile := filepath.Join(cfg.poolDir(version), resourceID+".conf")
	if _, err := os.Stat(poolFile); err != nil {
		t.Fatalf("expected a real pool fragment to exist at %s: %v", poolFile, err)
	}

	deleted, err := capability.Apply(ctx, newOp(protocol.OperationDelete, resourceID, 1, fpmPayload(accountID, d.Username, version)))
	requireApplied(t, "delete", deleted, err)

	if _, err := os.Stat(poolFile); !os.IsNotExist(err) {
		t.Fatalf("expected the pool fragment to be removed after delete, stat error: %v", err)
	}

	// A php-fpm reload is SIGUSR2 (the real unit's own ExecReload too),
	// which returns before the master has dropped the deleted pool.
	deadline := time.Now().Add(5 * time.Second)
	for dialUnixSocket(socketPath) == nil {
		if time.Now().After(deadline) {
			t.Fatal("expected the pool socket to no longer accept connections after delete")
		}
		time.Sleep(50 * time.Millisecond)
	}
}

// TestObserveDetectsRealDriftInTheLivePoolDirectory proves observe recomputes
// a real digest of the live poolDir rather than trusting stored state, by
// mutating the live fragment directly on disk (bypassing this capability
// entirely) and confirming observe reports StatusDegraded.
func TestObserveDetectsRealDriftInTheLivePoolDirectory(t *testing.T) {
	binary, version := requireRealPhpFpm(t)
	d := newDisposablePhpFpm(t, binary, version)

	cfg := d.Config
	capability := New(cfg)
	ctx := context.Background()

	resourceID := newTestUUID(t)
	accountID := 1

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, 1, fpmPayload(accountID, d.Username, version)))
	requireApplied(t, "create", created, err)

	poolFile := filepath.Join(cfg.poolDir(version), resourceID+".conf")
	if err := os.WriteFile(poolFile, []byte("; tampered directly on disk\n"), 0o644); err != nil {
		t.Fatalf("tampering with the live pool fragment: %v", err)
	}

	observed, err := capability.Apply(ctx, newOp(protocol.OperationObserve, resourceID, 1, fpmPayload(accountID, d.Username, version)))
	if err != nil {
		t.Fatalf("observe: unexpected error: %v", err)
	}

	if observed.Status != protocol.StatusDegraded {
		t.Fatalf("expected observe to report degraded after real on-disk tampering, got %s", observed.Status)
	}
}

func dialUnixSocket(path string) error {
	conn, err := net.DialTimeout("unix", path, 2*time.Second)
	if err != nil {
		return err
	}

	return conn.Close()
}
