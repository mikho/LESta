package opctx

import (
	"context"
	"testing"
	"time"
)

func TestHealthCheckLeavesTheReserveBeforeTheDeadline(t *testing.T) {
	deadline := time.Now().Add(time.Minute)
	parent, cancel := context.WithDeadline(context.Background(), deadline)
	defer cancel()

	ctx, cancelHealth := HealthCheck(parent)
	defer cancelHealth()

	got, ok := ctx.Deadline()
	if !ok || !got.Equal(deadline.Add(-RollbackReserve)) {
		t.Fatalf("deadline = %v, want %v", got, deadline.Add(-RollbackReserve))
	}
}

func TestHealthCheckHalvesAShortBudget(t *testing.T) {
	parent, cancel := context.WithTimeout(context.Background(), 10*time.Second)
	defer cancel()
	parentDeadline, _ := parent.Deadline()

	ctx, cancelHealth := HealthCheck(parent)
	defer cancelHealth()

	got, _ := ctx.Deadline()
	if left := parentDeadline.Sub(got); left < 4*time.Second || left > 6*time.Second {
		t.Fatalf("reserve = %v, want about 5s", left)
	}
}

func TestRollbackOutlivesAnExpiredOperationContext(t *testing.T) {
	parent, cancel := context.WithDeadline(context.Background(), time.Now().Add(-time.Second))
	defer cancel()

	ctx, cancelRollback := Rollback(parent)
	defer cancelRollback()

	if err := ctx.Err(); err != nil {
		t.Fatalf("rollback context already done: %v", err)
	}
	if got, ok := ctx.Deadline(); !ok || time.Until(got) <= RollbackTimeout-time.Second {
		t.Fatalf("rollback deadline = %v, want about %v from now", got, RollbackTimeout)
	}
}
