package contract

import (
	"context"
	"testing"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// Case is one entry in the shared contract suite. Run receives a fresh
// Capability (fake or real) and drives it through envelopes built the exact
// same way regardless of which implementation is under test.
type Case struct {
	Name string
	Run  func(t *testing.T, capability protocol.Capability)
}

// RunAgainst runs every Case as its own subtest against a freshly constructed
// capability (newCapability is called once per Case, so a real NginxCapability
// test gets its own fully disposable nginx process per case, never a shared
// one). A panic from the capability under test is caught and reported as a
// test failure rather than crashing the run, making "never a panic" (the
// invalid-domain case's own requirement) something this harness enforces for
// every case, not just that one.
func RunAgainst(t *testing.T, newCapability func(t *testing.T) protocol.Capability) {
	t.Helper()

	for _, c := range Cases {
		c := c
		t.Run(c.Name, func(t *testing.T) {
			defer func() {
				if r := recover(); r != nil {
					t.Fatalf("capability under test panicked: %v", r)
				}
			}()

			capability := newCapability(t)
			c.Run(t, capability)
		})
	}
}

// Cases is the shared table both fake.Capability and nginx.NginxCapability
// must satisfy.
var Cases = []Case{
	{Name: "duplicate idempotency key returns already_applied without re-rendering", Run: caseDuplicateIdempotencyKey},
	{Name: "create then suspend then unsuspend restores the original digest", Run: caseCreateSuspendUnsuspendRestoresDigest},
	{Name: "an invalid domain is rejected, never a panic", Run: caseInvalidDomainIsRejected},
	{Name: "update before create is rejected unknown_resource", Run: caseUpdateBeforeCreateIsRejected},
	{Name: "suspend before create is rejected unknown_resource", Run: caseSuspendBeforeCreateIsRejected},
	{Name: "unsuspend before create is rejected unknown_resource", Run: caseUnsuspendBeforeCreateIsRejected},
	{Name: "delete before create is rejected unknown_resource", Run: caseDeleteBeforeCreateIsRejected},
	{Name: "observe before create is rejected unknown_resource", Run: caseObserveBeforeCreateIsRejected},
	{Name: "observe after create reports applied", Run: caseObserveAfterCreateReportsApplied},
	{Name: "create on an existing resource is rejected resource_already_exists", Run: caseCreateOnExistingIsRejected},
	{Name: "unsupported web template is rejected", Run: caseUnsupportedWebTemplateIsRejected},
	{Name: "delete removes the resource and observe afterwards still reports applied", Run: caseDeleteThenObserveReportsApplied},
	{Name: "update after create is applied and changes the digest", Run: caseUpdateAfterCreateIsApplied},
}

func caseDuplicateIdempotencyKey(t *testing.T, capability protocol.Capability) {
	ctx := context.Background()
	resourceID := newUUID()
	idempotencyKey := newUUID()
	op := buildEnvelope(protocol.OperationCreate, resourceID, idempotencyKey, 1, domainFor(t), false)

	first, err := capability.Apply(ctx, op)
	requireApplied(t, "first create", first, err)

	// Re-submit the byte-identical envelope, as a real client retrying the
	// exact same request would. It must be recognized as a duplicate and
	// answered from the original receipt, never redoing the underlying
	// render/validate/activate/reload work: if it had, a fresh generation
	// would have been minted, and GenerationID would have moved on.
	second, err := capability.Apply(ctx, op)
	requireStatus(t, "duplicate create", second, err, protocol.StatusAlreadyApplied)

	if second.GenerationID != first.GenerationID {
		t.Fatalf("expected the duplicate to echo the original generation_id %q, got %q (implies re-rendering happened)", first.GenerationID, second.GenerationID)
	}
	if second.ObservedStateDigest != first.ObservedStateDigest {
		t.Fatalf("expected the duplicate to echo the original digest %q, got %q", first.ObservedStateDigest, second.ObservedStateDigest)
	}
}

func caseCreateSuspendUnsuspendRestoresDigest(t *testing.T, capability protocol.Capability) {
	ctx := context.Background()
	resourceID := newUUID()
	domain := domainFor(t)

	created, err := capability.Apply(ctx, buildEnvelope(protocol.OperationCreate, resourceID, newUUID(), 1, domain, false))
	requireApplied(t, "create", created, err)

	suspended, err := capability.Apply(ctx, buildEnvelope(protocol.OperationSuspend, resourceID, newUUID(), 2, domain, true))
	requireApplied(t, "suspend", suspended, err)

	if suspended.ObservedStateDigest == created.ObservedStateDigest {
		t.Fatalf("expected suspend to change the observed digest, both were %s", created.ObservedStateDigest)
	}

	unsuspended, err := capability.Apply(ctx, buildEnvelope(protocol.OperationUnsuspend, resourceID, newUUID(), 3, domain, false))
	requireApplied(t, "unsuspend", unsuspended, err)

	if unsuspended.ObservedStateDigest != created.ObservedStateDigest {
		t.Fatalf("expected unsuspend to restore the original digest: create=%s unsuspend=%s", created.ObservedStateDigest, unsuspended.ObservedStateDigest)
	}
}

func caseInvalidDomainIsRejected(t *testing.T, capability protocol.Capability) {
	ctx := context.Background()
	op := buildEnvelope(protocol.OperationCreate, newUUID(), newUUID(), 1, "not a valid domain!", false)

	result, err := capability.Apply(ctx, op)
	requireStatus(t, "create with an invalid domain", result, err, protocol.StatusRejected)
	requireErrorCode(t, "create with an invalid domain", result, "invalid_domain")
}

func caseUpdateBeforeCreateIsRejected(t *testing.T, capability protocol.Capability) {
	ctx := context.Background()
	op := buildEnvelope(protocol.OperationUpdate, newUUID(), newUUID(), 1, domainFor(t), false)

	result, err := capability.Apply(ctx, op)
	requireStatus(t, "update before create", result, err, protocol.StatusRejected)
	requireErrorCode(t, "update before create", result, "unknown_resource")
}

func caseSuspendBeforeCreateIsRejected(t *testing.T, capability protocol.Capability) {
	ctx := context.Background()
	op := buildEnvelope(protocol.OperationSuspend, newUUID(), newUUID(), 1, domainFor(t), true)

	result, err := capability.Apply(ctx, op)
	requireStatus(t, "suspend before create", result, err, protocol.StatusRejected)
	requireErrorCode(t, "suspend before create", result, "unknown_resource")
}

func caseUnsuspendBeforeCreateIsRejected(t *testing.T, capability protocol.Capability) {
	ctx := context.Background()
	op := buildEnvelope(protocol.OperationUnsuspend, newUUID(), newUUID(), 1, domainFor(t), false)

	result, err := capability.Apply(ctx, op)
	requireStatus(t, "unsuspend before create", result, err, protocol.StatusRejected)
	requireErrorCode(t, "unsuspend before create", result, "unknown_resource")
}

func caseDeleteBeforeCreateIsRejected(t *testing.T, capability protocol.Capability) {
	ctx := context.Background()
	op := buildEnvelope(protocol.OperationDelete, newUUID(), newUUID(), 1, domainFor(t), false)

	result, err := capability.Apply(ctx, op)
	requireStatus(t, "delete before create", result, err, protocol.StatusRejected)
	requireErrorCode(t, "delete before create", result, "unknown_resource")
}

func caseObserveBeforeCreateIsRejected(t *testing.T, capability protocol.Capability) {
	ctx := context.Background()
	op := buildEnvelope(protocol.OperationObserve, newUUID(), newUUID(), 1, domainFor(t), false)

	result, err := capability.Apply(ctx, op)
	requireStatus(t, "observe before create", result, err, protocol.StatusRejected)
	requireErrorCode(t, "observe before create", result, "unknown_resource")
}

func caseObserveAfterCreateReportsApplied(t *testing.T, capability protocol.Capability) {
	ctx := context.Background()
	resourceID := newUUID()
	domain := domainFor(t)

	created, err := capability.Apply(ctx, buildEnvelope(protocol.OperationCreate, resourceID, newUUID(), 1, domain, false))
	requireApplied(t, "create", created, err)

	observed, err := capability.Apply(ctx, buildEnvelope(protocol.OperationObserve, resourceID, newUUID(), 1, domain, false))
	requireApplied(t, "observe", observed, err)

	if observed.ObservedStateDigest != created.ObservedStateDigest {
		t.Fatalf("expected observe to report the same digest create just established: create=%s observe=%s", created.ObservedStateDigest, observed.ObservedStateDigest)
	}
}

func caseCreateOnExistingIsRejected(t *testing.T, capability protocol.Capability) {
	ctx := context.Background()
	resourceID := newUUID()
	domain := domainFor(t)

	created, err := capability.Apply(ctx, buildEnvelope(protocol.OperationCreate, resourceID, newUUID(), 1, domain, false))
	requireApplied(t, "first create", created, err)

	second, err := capability.Apply(ctx, buildEnvelope(protocol.OperationCreate, resourceID, newUUID(), 2, domain, false))
	requireStatus(t, "second create on the same resource", second, err, protocol.StatusRejected)
	requireErrorCode(t, "second create on the same resource", second, "resource_already_exists")
}

func caseUnsupportedWebTemplateIsRejected(t *testing.T, capability protocol.Capability) {
	ctx := context.Background()
	resourceID := newUUID()
	op := buildEnvelope(protocol.OperationCreate, resourceID, newUUID(), 1, domainFor(t), false)

	raw, err := marshalWithTemplate(op.Payload, "apache-classic")
	if err != nil {
		t.Fatalf("building payload with an unsupported web_template: %v", err)
	}
	op.Payload = raw

	result, applyErr := capability.Apply(ctx, op)
	requireStatus(t, "create with an unsupported web_template", result, applyErr, protocol.StatusRejected)
	requireErrorCode(t, "create with an unsupported web_template", result, "unsupported_web_template")
}

func caseDeleteThenObserveReportsApplied(t *testing.T, capability protocol.Capability) {
	ctx := context.Background()
	resourceID := newUUID()
	domain := domainFor(t)

	created, err := capability.Apply(ctx, buildEnvelope(protocol.OperationCreate, resourceID, newUUID(), 1, domain, false))
	requireApplied(t, "create", created, err)

	deleted, err := capability.Apply(ctx, buildEnvelope(protocol.OperationDelete, resourceID, newUUID(), 2, domain, false))
	requireApplied(t, "delete", deleted, err)

	if deleted.ObservedStateDigest == created.ObservedStateDigest {
		t.Fatalf("expected delete to change the observed digest (the fragment is gone), both were %s", created.ObservedStateDigest)
	}

	// A resource this node has generation history for, even a deleted one, is
	// never unknown_resource: only a resource_id this node has never seen at
	// all is. observe reports applied because the live state (no fragment)
	// matches what delete's own manifest recorded.
	observed, err := capability.Apply(ctx, buildEnvelope(protocol.OperationObserve, resourceID, newUUID(), 2, domain, false))
	requireApplied(t, "observe after delete", observed, err)
}

func caseUpdateAfterCreateIsApplied(t *testing.T, capability protocol.Capability) {
	ctx := context.Background()
	resourceID := newUUID()
	domain := domainFor(t)

	created, err := capability.Apply(ctx, buildEnvelope(protocol.OperationCreate, resourceID, newUUID(), 1, domain, false))
	requireApplied(t, "create", created, err)

	otherDomain := domainFor(t)
	updated, err := capability.Apply(ctx, buildEnvelope(protocol.OperationUpdate, resourceID, newUUID(), 2, otherDomain, false))
	requireApplied(t, "update", updated, err)

	if updated.ObservedStateDigest == created.ObservedStateDigest {
		t.Fatalf("expected update (a changed domain) to change the observed digest, both were %s", created.ObservedStateDigest)
	}
	if updated.ObservedStateVersion != 2 {
		t.Fatalf("expected observed_state_version to be 2 after update, got %d", updated.ObservedStateVersion)
	}
}
