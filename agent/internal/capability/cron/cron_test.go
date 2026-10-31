package cron_test

import (
	"context"
	"fmt"
	"os"
	"os/user"
	"path/filepath"
	"strconv"
	"syscall"
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
		ExecutionsGroup: testRunAs,
	}

	return cron.New(cfg), cfg
}

func fragmentPath(cfg cron.Config, resourceID string) string {
	return filepath.Join(cfg.FragmentDir, "lesta-"+resourceID)
}

func sidecarPath(cfg cron.Config, runAs, resourceID string) string {
	return filepath.Join(cfg.StateRoot, "accounts", runAs, "jobs", "sidecar", resourceID+".json")
}

func TestCreateWritesFragmentAndSidecarWithExpectedContent(t *testing.T) {
	capability, cfg := newCapability(t)
	requireChownableGroup(t)
	ctx := context.Background()

	resourceID := newTestUUID()

	result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1,
		cronPayload("0", "3", "*", "*", "*", "php artisan backup:run", false)))
	requireApplied(t, "create", result, err)

	fragment, err := os.ReadFile(fragmentPath(cfg, resourceID))
	if err != nil {
		t.Fatalf("expected the crontab fragment to exist: %v", err)
	}

	wantLine := fmt.Sprintf("0 3 * * * %s /var/lib/lesta/agent/bin/lesta-agent cron-run %s %s\n", testRunAs, resourceID, testRunAs)
	if string(fragment) != wantLine {
		t.Fatalf("expected fragment content %q, got %q", wantLine, string(fragment))
	}

	sidecar, err := os.ReadFile(sidecarPath(cfg, testRunAs, resourceID))
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
	requireChownableGroup(t)
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
	requireChownableGroup(t)
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

	want := fmt.Sprintf("30 4 * * 1-5 %s /var/lib/lesta/agent/bin/lesta-agent cron-run %s %s\n", testRunAs, resourceID, testRunAs)
	if string(fragment) != want {
		t.Fatalf("expected updated fragment content %q, got %q", want, string(fragment))
	}

	sidecar, err := os.ReadFile(sidecarPath(cfg, testRunAs, resourceID))
	if err != nil {
		t.Fatalf("expected the sidecar to exist: %v", err)
	}
	if string(sidecar) != `{"command":"new command"}` {
		t.Fatalf("expected updated sidecar content, got %q", string(sidecar))
	}
}

func TestDeleteRemovesBothFilesAndToleratesRepeat(t *testing.T) {
	capability, cfg := newCapability(t)
	requireChownableGroup(t)
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
	if _, err := os.Stat(sidecarPath(cfg, testRunAs, resourceID)); !os.IsNotExist(err) {
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
	requireChownableGroup(t)
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
	requireChownableGroup(t)
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

func TestCreatePreparesTheAccountsExecutionLogDirectory(t *testing.T) {
	capability, cfg := newCapability(t)
	requireChownableGroup(t)

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1,
		cronPayload("0", "3", "*", "*", "*", "true", false)))
	requireApplied(t, "create", result, err)

	root, err := os.Stat(filepath.Join(cfg.StateRoot, "executions"))
	if err != nil {
		t.Fatalf("expected StateRoot/executions to exist: %v", err)
	}
	if root.Mode().Perm() != 0o751 {
		t.Fatalf("StateRoot/executions mode = %v, want 0751", root.Mode().Perm())
	}

	dir, err := os.Stat(cron.ExecutionsDirFor(cfg.StateRoot, testRunAs))
	if err != nil {
		t.Fatalf("expected the account's execution log directory to exist: %v", err)
	}
	if dir.Mode().Perm() != 0o750 {
		t.Fatalf("execution log directory mode = %v, want 0750", dir.Mode())
	}
}

func TestASidecarIsReadableByItsAccountsGroupWithoutRelyingOnSetgid(t *testing.T) {
	capability, cfg := newCapability(t)
	requireChownableGroup(t)

	resourceID := newTestUUID()
	result, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1,
		cronPayload("0", "3", "*", "*", "*", "true", false)))
	requireApplied(t, "create", result, err)

	grp, err := user.LookupGroup(testRunAs)
	if err != nil {
		t.Fatal(err)
	}

	for _, path := range []string{sidecarPath(cfg, testRunAs, resourceID), filepath.Dir(sidecarPath(cfg, testRunAs, resourceID))} {
		info, err := os.Stat(path)
		if err != nil {
			t.Fatal(err)
		}

		if gid := strconv.Itoa(int(info.Sys().(*syscall.Stat_t).Gid)); gid != grp.Gid {
			t.Errorf("%s has group %s, want %s (%s)", path, gid, grp.Gid, testRunAs)
		}
	}
}
