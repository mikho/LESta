package cron_test

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/cron"
	"github.com/mikho/LESta/agent/internal/protocol"
)

func newCapability(t *testing.T) (*cron.CronCapability, cron.Config) {
	t.Helper()

	cfg := cron.Config{
		FragmentDir:     filepath.Join(t.TempDir(), "cron.d"),
		StateRoot:       t.TempDir(),
		RunnerUser:      "lesta-cron",
		AgentBinaryPath: "/var/lib/lesta/agent/bin/lesta-agent",
	}

	return cron.New(cfg), cfg
}

func fragmentPath(cfg cron.Config, resourceID string) string {
	return filepath.Join(cfg.FragmentDir, "lesta-"+resourceID)
}

func sidecarPath(cfg cron.Config, resourceID string) string {
	return filepath.Join(cfg.StateRoot, "jobs", "sidecar", resourceID+".json")
}

func TestCreateWritesFragmentAndSidecarWithExpectedContent(t *testing.T) {
	capability, cfg := newCapability(t)
	ctx := context.Background()

	resourceID := newTestUUID()

	result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1,
		cronPayload("0", "3", "*", "*", "*", "php artisan backup:run", false)))
	requireApplied(t, "create", result, err)

	fragment, err := os.ReadFile(fragmentPath(cfg, resourceID))
	if err != nil {
		t.Fatalf("expected the crontab fragment to exist: %v", err)
	}

	wantLine := fmt.Sprintf("0 3 * * * lesta-cron /var/lib/lesta/agent/bin/lesta-agent cron-run %s\n", resourceID)
	if string(fragment) != wantLine {
		t.Fatalf("expected fragment content %q, got %q", wantLine, string(fragment))
	}

	sidecar, err := os.ReadFile(sidecarPath(cfg, resourceID))
	if err != nil {
		t.Fatalf("expected the sidecar to exist: %v", err)
	}
	if string(sidecar) != `{"command":"php artisan backup:run"}` {
		t.Fatalf("expected sidecar content to carry only the real command, got %q", string(sidecar))
	}

	// The atomic write-then-rename helper must never leave a ".tmp" sibling
	// behind after a successful write.
	if _, err := os.Stat(fragmentPath(cfg, resourceID) + ".tmp"); !os.IsNotExist(err) {
		t.Fatalf("expected no leftover .tmp fragment file, stat err=%v", err)
	}
}

func TestCreateSuspendedWritesCommentOnlyPlaceholder(t *testing.T) {
	capability, cfg := newCapability(t)
	ctx := context.Background()

	resourceID := newTestUUID()

	result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1,
		cronPayload("*", "*", "*", "*", "*", "echo hello", true)))
	requireApplied(t, "create suspended", result, err)

	fragment, err := os.ReadFile(fragmentPath(cfg, resourceID))
	if err != nil {
		t.Fatalf("expected the crontab fragment to exist even suspended: %v", err)
	}

	want := fmt.Sprintf("# lesta-cron: job %s suspended\n", resourceID)
	if string(fragment) != want {
		t.Fatalf("expected a comment-only placeholder %q, got %q", want, string(fragment))
	}
}

func TestUpdateRewritesBothFilesIntoANewGeneration(t *testing.T) {
	capability, cfg := newCapability(t)
	ctx := context.Background()

	resourceID := newTestUUID()

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1,
		cronPayload("0", "3", "*", "*", "*", "old command", false)))
	requireApplied(t, "create", created, err)

	updated, err := capability.Apply(ctx, newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 2,
		cronPayload("30", "4", "*", "*", "1-5", "new command", false)))
	requireApplied(t, "update", updated, err)

	if updated.GenerationID == created.GenerationID {
		t.Fatalf("expected update to land in a new generation, got the same generation_id %q", created.GenerationID)
	}

	fragment, err := os.ReadFile(fragmentPath(cfg, resourceID))
	if err != nil {
		t.Fatalf("expected the crontab fragment to exist: %v", err)
	}

	want := fmt.Sprintf("30 4 * * 1-5 lesta-cron /var/lib/lesta/agent/bin/lesta-agent cron-run %s\n", resourceID)
	if string(fragment) != want {
		t.Fatalf("expected updated fragment content %q, got %q", want, string(fragment))
	}

	sidecar, err := os.ReadFile(sidecarPath(cfg, resourceID))
	if err != nil {
		t.Fatalf("expected the sidecar to exist: %v", err)
	}
	if string(sidecar) != `{"command":"new command"}` {
		t.Fatalf("expected updated sidecar content, got %q", string(sidecar))
	}
}

func TestDeleteRemovesBothFilesAndToleratesRepeat(t *testing.T) {
	capability, cfg := newCapability(t)
	ctx := context.Background()

	resourceID := newTestUUID()

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1,
		cronPayload("*", "*", "*", "*", "*", "echo hello", false)))
	requireApplied(t, "create", created, err)

	deleted, err := capability.Apply(ctx, newOp(protocol.OperationDelete, resourceID, newTestUUID(), 2,
		cronPayload("*", "*", "*", "*", "*", "echo hello", false)))
	requireApplied(t, "delete", deleted, err)

	if _, err := os.Stat(fragmentPath(cfg, resourceID)); !os.IsNotExist(err) {
		t.Fatalf("expected the fragment to be removed after delete, stat err=%v", err)
	}
	if _, err := os.Stat(sidecarPath(cfg, resourceID)); !os.IsNotExist(err) {
		t.Fatalf("expected the sidecar to be removed after delete, stat err=%v", err)
	}

	// A retried delete (or a delete for a create that never landed) stays
	// idempotent, matching acme's own established pattern.
	deletedAgain, err := capability.Apply(ctx, newOp(protocol.OperationDelete, resourceID, newTestUUID(), 3,
		cronPayload("*", "*", "*", "*", "*", "echo hello", false)))
	requireApplied(t, "delete again", deletedAgain, err)
}

func TestObserveDetectsDriftWhenFragmentIsExternallyModified(t *testing.T) {
	capability, cfg := newCapability(t)
	ctx := context.Background()

	resourceID := newTestUUID()

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1,
		cronPayload("*", "*", "*", "*", "*", "echo hello", false)))
	requireApplied(t, "create", created, err)

	observed, err := capability.Apply(ctx, newOp(protocol.OperationObserve, resourceID, newTestUUID(), 1,
		cronPayload("*", "*", "*", "*", "*", "echo hello", false)))
	requireApplied(t, "observe with no drift", observed, err)

	if err := os.WriteFile(fragmentPath(cfg, resourceID), []byte("* * * * * root tampered\n"), 0o644); err != nil {
		t.Fatalf("tampering with the fragment file: %v", err)
	}

	drifted, err := capability.Apply(ctx, newOp(protocol.OperationObserve, resourceID, newTestUUID(), 1,
		cronPayload("*", "*", "*", "*", "*", "echo hello", false)))
	requireStatus(t, "observe after external tampering", drifted, err, protocol.StatusDegraded)
	requireErrorCode(t, "observe after external tampering", drifted, "drift_detected")
}

func TestDuplicateIdempotencyKeyIsServedFromReceiptNotReapplied(t *testing.T) {
	capability, _ := newCapability(t)
	ctx := context.Background()

	resourceID := newTestUUID()
	idempotencyKey := newTestUUID()
	op := newOp(protocol.OperationCreate, resourceID, idempotencyKey, 1,
		cronPayload("*", "*", "*", "*", "*", "echo hello", false))

	first, err := capability.Apply(ctx, op)
	requireApplied(t, "first create", first, err)

	second, err := capability.Apply(ctx, op)
	requireStatus(t, "duplicate create", second, err, protocol.StatusAlreadyApplied)

	if second.GenerationID != first.GenerationID {
		t.Fatalf("expected the duplicate to echo the original generation_id %q, got %q", first.GenerationID, second.GenerationID)
	}
}

func TestApplyRejectsAnOutOfRangeScheduleField(t *testing.T) {
	capability, _ := newCapability(t)
	ctx := context.Background()

	resourceID := newTestUUID()

	result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1,
		cronPayload("60", "*", "*", "*", "*", "echo hello", false)))
	requireStatus(t, "create with minute=60", result, err, protocol.StatusRejected)
	requireErrorCode(t, "create with minute=60", result, "invalid_schedule_field")
}

func TestApplyRejectsACommandContainingANewline(t *testing.T) {
	capability, _ := newCapability(t)
	ctx := context.Background()

	resourceID := newTestUUID()

	result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1,
		cronPayload("*", "*", "*", "*", "*", "echo hello\nrm -rf /", false)))
	requireStatus(t, "create with a newline in command", result, err, protocol.StatusRejected)
	requireErrorCode(t, "create with a newline in command", result, "invalid_command")
}
