package main

import (
	"context"
	"sync"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// countingCapability applies every operation it is given and counts them.
type countingCapability struct{ applied int }

func (c *countingCapability) Apply(_ context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	c.applied++

	return protocol.ResultEnvelope{Capability: op.Capability, ResourceID: op.ResourceID, IdempotencyKey: op.IdempotencyKey, Status: protocol.StatusApplied, CompletedAt: time.Now().UTC()}, nil
}

func TestARedeliveredOperationIsAnsweredFromItsReceiptAfterAnApplierRestart(t *testing.T) {
	appliedReceiptsRoot = t.TempDir()

	op := protocol.OperationEnvelope{
		Capability:     webNginxCapability,
		Operation:      protocol.OperationCreate,
		ResourceID:     "11111111-2222-4333-8444-555555555555",
		IdempotencyKey: "0f8e4d2a-1b3c-4d5e-8f70-123456789abc",
	}

	first := &countingCapability{}
	restartApplierWith(t, first)

	if result, err := dispatchHosted(context.Background(), op); err != nil || result.Status != protocol.StatusApplied {
		t.Fatalf("first delivery: %+v, %v", result, err)
	}

	second := &countingCapability{}
	restartApplierWith(t, second)

	result, err := dispatchHosted(context.Background(), op)
	if err != nil || result.Status != protocol.StatusAlreadyApplied {
		t.Fatalf("redelivery after a restart: %+v, %v, want already_applied", result, err)
	}

	if second.applied != 0 {
		t.Fatalf("the redelivered operation was applied again (%d times)", second.applied)
	}
}

// restartApplierWith forgets every hosted instance, as a restarted applier
// has, and hosts capability as nginx.
func restartApplierWith(t *testing.T, capability protocol.Capability) {
	t.Helper()

	hostedMu.Lock()
	defer hostedMu.Unlock()

	hostedInstances = map[string]protocol.Capability{webNginxCapability: capability}
	hostedLocks = map[string]*sync.Mutex{webNginxCapability: {}}
}
