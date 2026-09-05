// Package identity implements the system.account-identity.v1 capability:
// real per-tenant-account Linux system users, one per (account, node) pair,
// created lazily the first time that account gets a cron job on a node (see
// app/Actions/Cron/EnsuresAccountNodeIdentity), so scheduler.account-cron.v1
// can run each account's own cron jobs under its own dedicated, non-root
// identity instead of the single shared lesta-cron user every account used
// to share.
//
// Like internal/capability/acme, this capability never renders a template,
// never invokes a reload binary, and never runs a health-check poll -- but
// unlike acme, it does exec real external commands (useradd/userdel/id),
// mirroring internal/capability/mariadb's own "exec a real binary, treat a
// well-formed non-zero exit as StatusFailed, only an inability to even run
// the binary as a bare Go error" discipline exactly. There is no rollback
// path for the same reason acme's own package doc comment gives: a useradd
// or userdel invocation either succeeds outright or fails outright, never
// leaving a half-applied intermediate state a restore step would need to
// unwind.
//
// Only create and delete do real work. update/suspend/unsuspend/observe are
// rejected outright: a system user has no meaningful "desired state" beyond
// existing or not (no schedule, no content, nothing to update in place; the
// deterministic username itself is immutable for the account's lifetime),
// and there is no cheap way to "observe" more than userExists already checks
// inline on every create/delete anyway, so a separate read-only verb would
// add no information a caller couldn't get from a create's own
// already_applied response.
package identity

import (
	"context"
	"errors"
	"fmt"
	"time"

	"github.com/mikho/LESta/agent/internal/idempotency"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// IdentityCapability implements protocol.Capability for
// system.account-identity.v1.
type IdentityCapability struct {
	cfg      Config
	receipts *idempotency.Store
}

// New returns an IdentityCapability using cfg.
func New(cfg Config) *IdentityCapability {
	return &IdentityCapability{
		cfg:      cfg,
		receipts: idempotency.New(),
	}
}

// Apply implements protocol.Capability.
func (c *IdentityCapability) Apply(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	ctx, cancel := context.WithDeadline(ctx, op.Deadline)
	defer cancel()

	if prior, ok := c.receipts.Lookup(op.IdempotencyKey); ok {
		if prior.Status == protocol.StatusApplied || prior.Status == protocol.StatusAlreadyApplied {
			prior.Status = protocol.StatusAlreadyApplied
		}

		return prior, nil
	}

	var (
		result protocol.ResultEnvelope
		err    error
	)

	switch op.Operation {
	case protocol.OperationCreate:
		result, err = c.applyCreate(ctx, op)
	case protocol.OperationDelete:
		result, err = c.applyDelete(ctx, op)
	default:
		result, err = c.rejected(op, "unsupported_operation",
			fmt.Sprintf("operation %q is not supported; system.account-identity.v1 only implements create and delete", op.Operation), "")
	}

	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	c.receipts.Record(op.IdempotencyKey, result)

	return result, nil
}

// applyCreate is idempotent: an already-existing username is
// StatusAlreadyApplied, never re-run through useradd and never an error.
func (c *IdentityCapability) applyCreate(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParsePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(ctx, op, verr)
	}

	exists, err := userExists(ctx, c.cfg, payload.Username)
	if err != nil {
		return c.failed(ctx, op, "id_check_failed", err.Error())
	}

	if exists {
		return c.buildResult(ctx, op, protocol.StatusAlreadyApplied, op.DesiredStateVersion, payload.Username, nil)
	}

	if err := createSystemUser(ctx, c.cfg, payload.Username); err != nil {
		return c.failed(ctx, op, "useradd_failed", err.Error())
	}

	return c.buildResult(ctx, op, protocol.StatusApplied, op.DesiredStateVersion, payload.Username, nil)
}

// applyDelete is idempotent: an already-absent username is
// StatusAlreadyApplied, never an error.
func (c *IdentityCapability) applyDelete(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParsePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(ctx, op, verr)
	}

	exists, err := userExists(ctx, c.cfg, payload.Username)
	if err != nil {
		return c.failed(ctx, op, "id_check_failed", err.Error())
	}

	if !exists {
		return c.buildResult(ctx, op, protocol.StatusAlreadyApplied, op.DesiredStateVersion, payload.Username, nil)
	}

	if err := deleteSystemUser(ctx, c.cfg, payload.Username); err != nil {
		return c.failed(ctx, op, "userdel_failed", err.Error())
	}

	return c.buildResult(ctx, op, protocol.StatusApplied, op.DesiredStateVersion, payload.Username, nil)
}

func (c *IdentityCapability) rejectedFromValidationError(_ context.Context, op protocol.OperationEnvelope, err error) (protocol.ResultEnvelope, error) {
	var ve *ValidationError
	if errors.As(err, &ve) {
		return c.rejected(op, ve.Code, ve.Message, ve.Field)
	}

	return protocol.ResultEnvelope{}, err
}

// rejected reports a well-formed payload rejection. There is no known
// username to key a live digest check on (parsing failed before one was
// ever established), so the digest falls back to zeroDigest, and the
// generation identifier is always "none": this capability keeps no
// generation history at all (see this package's own doc comment on why).
func (c *IdentityCapability) rejected(op protocol.OperationEnvelope, code, message, field string) (protocol.ResultEnvelope, error) {
	var fieldPtr *string
	if field != "" {
		fieldPtr = &field
	}

	errs := []protocol.ResultError{{Code: code, Message: message, Field: fieldPtr}}

	return c.buildResultWithDigest(op, protocol.StatusRejected, 0, "none", zeroDigest, errs), nil
}

// failed reports a real, well-formed operational failure (useradd/userdel
// rejected the request, an `id` invocation could not even run) as
// protocol.StatusFailed, never a bare Go error. The live digest is still
// recomputed fresh: the username is known at this point (payload already
// parsed successfully), so a real "present"/"absent" digest is always
// available even on failure.
func (c *IdentityCapability) failed(ctx context.Context, op protocol.OperationEnvelope, code, message string) (protocol.ResultEnvelope, error) {
	payload, verr := ParsePayload(op.Payload)

	digest := zeroDigest
	if verr == nil {
		if d, err := computeDigest(ctx, c.cfg, payload.Username); err == nil {
			digest = d
		}
	}

	return c.buildResultWithDigest(op, protocol.StatusFailed, 0, "none", digest, []protocol.ResultError{{Code: code, Message: message}}), nil
}

// buildResult recomputes username's live digest fresh and builds the
// resulting ResultEnvelope for a create/delete outcome that has a known-good
// username in hand.
func (c *IdentityCapability) buildResult(ctx context.Context, op protocol.OperationEnvelope, status protocol.Status, observedVersion int, username string, errs []protocol.ResultError) (protocol.ResultEnvelope, error) {
	digest, err := computeDigest(ctx, c.cfg, username)
	if err != nil {
		return protocol.ResultEnvelope{}, fmt.Errorf("computing observed digest for %s: %w", username, err)
	}

	return c.buildResultWithDigest(op, status, observedVersion, "none", digest, errs), nil
}

func (c *IdentityCapability) buildResultWithDigest(op protocol.OperationEnvelope, status protocol.Status, observedVersion int, generationID, digest string, errs []protocol.ResultError) protocol.ResultEnvelope {
	if errs == nil {
		errs = []protocol.ResultError{}
	}

	return protocol.ResultEnvelope{
		ProtocolVersion:      op.ProtocolVersion,
		Capability:           op.Capability,
		ResourceID:           op.ResourceID,
		IdempotencyKey:       op.IdempotencyKey,
		CorrelationID:        op.CorrelationID,
		Status:               status,
		ObservedStateVersion: observedVersion,
		ObservedStateDigest:  digest,
		GenerationID:         generationID,
		Errors:               errs,
		CompletedAt:          time.Now().UTC(),
	}
}
