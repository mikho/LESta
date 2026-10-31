// Package opctx splits an operation's single deadline between the health
// check that proves a new generation works and the rollback that runs when it
// does not. Without the split a failing health check polls until the
// operation's deadline has passed, and the rollback then starts with an
// already expired context and fails on its first command.
package opctx

import (
	"context"
	"time"
)

// RollbackReserve is the part of an operation's deadline a health check
// leaves unused, so a rollback that follows still fits inside the deadline.
const RollbackReserve = 15 * time.Second

// RollbackTimeout bounds a rollback on its own, independent of the operation
// context it was started from.
const RollbackTimeout = 30 * time.Second

// HealthCheck returns ctx shortened so that RollbackReserve (or half the
// remaining time, when less than twice the reserve is left) remains before
// ctx's deadline. A ctx without a deadline is returned unchanged.
func HealthCheck(ctx context.Context) (context.Context, context.CancelFunc) {
	deadline, ok := ctx.Deadline()
	if !ok {
		return context.WithCancel(ctx)
	}

	reserve := RollbackReserve
	if remaining := time.Until(deadline); remaining < 2*reserve {
		reserve = remaining / 2
	}

	return context.WithDeadline(ctx, deadline.Add(-reserve))
}

// Rollback returns a context that keeps ctx's values but not its
// cancellation or deadline, limited to RollbackTimeout. Restoring the previous
// generation must run even when the operation's own context is already done.
func Rollback(ctx context.Context) (context.Context, context.CancelFunc) {
	return context.WithTimeout(context.WithoutCancel(ctx), RollbackTimeout)
}
