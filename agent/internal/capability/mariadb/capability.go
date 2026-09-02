// Package mariadb implements the database.tenant.v1 capability: real tenant
// MariaDB databases, provisioned by execing the real mariadb CLI client
// against the tenant instance (port 3307; see .install/services/mariadb),
// piping validated, backtick-quoted DDL over stdin -- mirroring nginx/
// apache/bind9's own "exec a real binary" pattern and preserving this
// module's zero-external-Go-dependency policy rather than adding a MySQL
// driver.
//
// Unlike every other capability in this module, there is no local file
// representing "what's live" at all: the live state is entirely inside the
// tenant mariadbd process, reachable only via the mariadb CLI itself (see
// digest.go's own computeDigest, which queries information_schema.SCHEMATA
// and SHOW GRANTS FOR rather than walking a directory). See payload.go for
// the request shape and validation, exec.go for the DDL builders and the
// real client invocation (exec.go's own rotateDDL doc comment explains the
// single most important correctness detail in this whole capability: why
// password rotation must use ALTER USER, never CREATE OR REPLACE USER), and
// this file for wiring the six protocol operations through one shared
// internal pipeline.
package mariadb

import (
	"context"
	"errors"
	"fmt"
	"path/filepath"
	"strconv"
	"time"

	"github.com/mikho/LESta/agent/internal/generation"
	"github.com/mikho/LESta/agent/internal/idempotency"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// zeroDigest is the fixed placeholder observed_state_digest used only when
// this node has no generation history at all yet AND a fresh live query
// cannot be attempted (payload doesn't even decode, e.g. a malformed
// envelope's very first rejection). Every other path computes a real digest,
// either fresh (buildResult's normal path) or from the last known-good
// generation manifest (currentDigestOrFallback's second branch).
const zeroDigest = "sha256:0000000000000000000000000000000000000000000000000000000000000000"

// MariaDBCapability implements protocol.Capability for database.tenant.v1.
type MariaDBCapability struct {
	cfg      Config
	store    *generation.Store
	receipts *idempotency.Store
}

// New returns a MariaDBCapability using cfg. Generation history (a monotonic
// per-resource-id operation log used only for idempotency bookkeeping and
// observe's own drift detection -- never a rollback target, see Apply's own
// doc comment) nests under cfg.StateRoot/databases, mirroring nginx's/
// bind9's/acme's own StateRoot/<noun> convention.
func New(cfg Config) *MariaDBCapability {
	return &MariaDBCapability{
		cfg:      cfg,
		store:    generation.New(filepath.Join(cfg.StateRoot, "databases")),
		receipts: idempotency.New(),
	}
}

// Apply implements protocol.Capability. There is no rollback/recovery path
// here (contrast bind9's own recoverFromFailure): a DDL statement either
// succeeds outright (StatusApplied) or fails outright (StatusFailed, via
// failed() below) -- there is no intermediate "staged but not yet activated"
// state a failure could leave half-applied that a restore step would need to
// unwind, the same rationale internal/capability/acme's own package doc
// comment gives for having no rollback path of its own.
func (c *MariaDBCapability) Apply(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	ctx, cancel := context.WithDeadline(ctx, op.Deadline)
	defer cancel()

	// observe never applies anything, so it is never deduplicated: there is
	// no side effect a duplicate observe could redundantly redo.
	if op.Operation != protocol.OperationObserve {
		if prior, ok := c.receipts.Lookup(op.IdempotencyKey); ok {
			if prior.Status == protocol.StatusApplied || prior.Status == protocol.StatusAlreadyApplied {
				prior.Status = protocol.StatusAlreadyApplied
			}

			return prior, nil
		}
	}

	var (
		result protocol.ResultEnvelope
		err    error
	)

	switch op.Operation {
	case protocol.OperationCreate:
		result, err = c.applyCreate(ctx, op)
	case protocol.OperationUpdate:
		result, err = c.applyRotate(ctx, op)
	case protocol.OperationSuspend:
		result, err = c.applySuspend(ctx, op)
	case protocol.OperationUnsuspend:
		result, err = c.applyUnsuspend(ctx, op)
	case protocol.OperationDelete:
		result, err = c.applyDelete(ctx, op)
	case protocol.OperationObserve:
		result, err = c.observe(ctx, op)
	default:
		result, err = c.rejected(ctx, op, "unsupported_operation", fmt.Sprintf("operation %q is not supported", op.Operation), "")
	}

	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if op.Operation != protocol.OperationObserve {
		c.receipts.Record(op.IdempotencyKey, result)
	}

	return result, nil
}

// applyCreate: CREATE DATABASE IF NOT EXISTS, CREATE OR REPLACE USER
// IDENTIFIED BY, GRANT ALL PRIVILEGES, FLUSH PRIVILEGES (exec.go's own
// createDDL). Requires no prior generation: a create against a resource_id
// this node has already provisioned is rejected outright, matching bind9's
// own requirePrior semantics.
func (c *MariaDBCapability) applyCreate(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParsePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(ctx, op, verr)
	}

	if verr := payload.requirePassword(); verr != nil {
		return c.rejectedFromValidationError(ctx, op, verr)
	}

	hasCurrent, err := c.store.HasCurrent(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}
	if hasCurrent {
		return c.rejected(ctx, op, "resource_already_exists", "a generation already exists for this resource; use update instead of create", "")
	}

	if _, err := runSQL(ctx, c.cfg, createDDL(payload.DatabaseName, payload.DatabaseUser, *payload.Password)); err != nil {
		return c.failed(ctx, op, "ddl_failed", err.Error())
	}

	return c.recordGenerationAndBuildResult(ctx, op, payload, false)
}

// applyRotate handles ProvisioningVerb::Update, which -- for this capability
// alone among this module's own -- unambiguously means "rotate the
// password": database_name/database_user/label are all immutable after
// creation on the Laravel side (see app/Actions/TenantDatabases's own
// package doc comment), so RotateTenantDatabasePassword is the only action
// that ever issues `update` for database.tenant.v1 at all, and its payload
// always carries a password. Requires a prior generation.
func (c *MariaDBCapability) applyRotate(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParsePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(ctx, op, verr)
	}

	if verr := payload.requirePassword(); verr != nil {
		return c.rejectedFromValidationError(ctx, op, verr)
	}

	hasCurrent, err := c.store.HasCurrent(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}
	if !hasCurrent {
		return c.rejected(ctx, op, "unknown_resource", "no prior generation exists for this resource on this node", "")
	}

	if _, err := runSQL(ctx, c.cfg, rotateDDL(payload.DatabaseUser, *payload.Password)); err != nil {
		return c.failed(ctx, op, "ddl_failed", err.Error())
	}

	return c.recordGenerationAndBuildResult(ctx, op, payload, false)
}

// applySuspend: REVOKE ALL PRIVILEGES, GRANT OPTION FROM, FLUSH PRIVILEGES.
// Requires a prior generation and a payload whose own suspended flag is
// true, matching what TenantDatabase::toProvisioningPayload() always sends
// for this verb -- defense in depth against a caller bug, not something
// Laravel would ever violate on its own.
func (c *MariaDBCapability) applySuspend(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParsePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(ctx, op, verr)
	}

	if verr := payload.forbidPassword(); verr != nil {
		return c.rejectedFromValidationError(ctx, op, verr)
	}

	if !payload.Suspended {
		return c.rejected(ctx, op, "suspended_mismatch", "payload.suspended must be true for a suspend operation", "suspended")
	}

	hasCurrent, err := c.store.HasCurrent(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}
	if !hasCurrent {
		return c.rejected(ctx, op, "unknown_resource", "no prior generation exists for this resource on this node", "")
	}

	if _, err := runSQL(ctx, c.cfg, suspendDDL(payload.DatabaseUser)); err != nil {
		return c.failed(ctx, op, "ddl_failed", err.Error())
	}

	return c.recordGenerationAndBuildResult(ctx, op, payload, false)
}

// applyUnsuspend: re-GRANT, no IDENTIFIED BY -- restores access without
// touching the credential. Requires a prior generation and payload.suspended
// == false.
func (c *MariaDBCapability) applyUnsuspend(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParsePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(ctx, op, verr)
	}

	if verr := payload.forbidPassword(); verr != nil {
		return c.rejectedFromValidationError(ctx, op, verr)
	}

	if payload.Suspended {
		return c.rejected(ctx, op, "suspended_mismatch", "payload.suspended must be false for an unsuspend operation", "suspended")
	}

	hasCurrent, err := c.store.HasCurrent(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}
	if !hasCurrent {
		return c.rejected(ctx, op, "unknown_resource", "no prior generation exists for this resource on this node", "")
	}

	if _, err := runSQL(ctx, c.cfg, unsuspendDDL(payload.DatabaseName, payload.DatabaseUser)); err != nil {
		return c.failed(ctx, op, "ddl_failed", err.Error())
	}

	return c.recordGenerationAndBuildResult(ctx, op, payload, false)
}

// applyDelete: DROP DATABASE IF EXISTS, DROP USER IF EXISTS, FLUSH
// PRIVILEGES. Requires a prior generation.
func (c *MariaDBCapability) applyDelete(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParsePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(ctx, op, verr)
	}

	if verr := payload.forbidPassword(); verr != nil {
		return c.rejectedFromValidationError(ctx, op, verr)
	}

	hasCurrent, err := c.store.HasCurrent(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}
	if !hasCurrent {
		return c.rejected(ctx, op, "unknown_resource", "no prior generation exists for this resource on this node", "")
	}

	if _, err := runSQL(ctx, c.cfg, deleteDDL(payload.DatabaseName, payload.DatabaseUser)); err != nil {
		return c.failed(ctx, op, "ddl_failed", err.Error())
	}

	return c.recordGenerationAndBuildResult(ctx, op, payload, true)
}

// observe is read-only: it recomputes live server state (computeDigest) and
// compares it to the recorded generation's manifest. It never runs a
// mutating DDL statement.
func (c *MariaDBCapability) observe(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParsePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(ctx, op, verr)
	}

	if verr := payload.forbidPassword(); verr != nil {
		return c.rejectedFromValidationError(ctx, op, verr)
	}

	n, ok, err := c.store.CurrentGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}
	if !ok {
		return c.rejected(ctx, op, "unknown_resource", "no generation history exists for this resource on this node", "")
	}

	manifest, err := c.store.ReadManifest(op.ResourceID, n)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	liveDigest, err := computeDigest(ctx, c.cfg, payload.DatabaseName, payload.DatabaseUser)
	if err != nil {
		return c.failed(ctx, op, "observe_query_failed", err.Error())
	}

	if liveDigest == manifest.Digest {
		return c.buildResult(ctx, op, protocol.StatusApplied, manifest.DesiredStateVersion, strconv.Itoa(n), nil)
	}

	return c.buildResult(ctx, op, protocol.StatusDegraded, manifest.DesiredStateVersion, strconv.Itoa(n), []protocol.ResultError{
		{Code: "drift_detected", Message: fmt.Sprintf("live digest %s does not match generation %d's recorded digest %s", liveDigest, n, manifest.Digest)},
	})
}

// recordGenerationAndBuildResult persists payload (redacted -- see
// Payload.marshalMeta's own doc comment) as this resource's next
// generation's metadata, computes the live digest fresh, activates the
// generation, and builds the resulting ResultEnvelope.
func (c *MariaDBCapability) recordGenerationAndBuildResult(ctx context.Context, op protocol.OperationEnvelope, payload Payload, deleted bool) (protocol.ResultEnvelope, error) {
	n, err := c.store.NextGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	digest, err := computeDigest(ctx, c.cfg, payload.DatabaseName, payload.DatabaseUser)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	metaBytes, err := payload.marshalMeta()
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.store.Activate(op.ResourceID, n, metaBytes, deleted, digest, op.DesiredStateVersion); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	return c.buildResult(ctx, op, protocol.StatusApplied, op.DesiredStateVersion, strconv.Itoa(n), nil)
}

func (c *MariaDBCapability) rejectedFromValidationError(ctx context.Context, op protocol.OperationEnvelope, err error) (protocol.ResultEnvelope, error) {
	var ve *ValidationError
	if errors.As(err, &ve) {
		return c.rejected(ctx, op, ve.Code, ve.Message, ve.Field)
	}

	return protocol.ResultEnvelope{}, err
}

func (c *MariaDBCapability) rejected(ctx context.Context, op protocol.OperationEnvelope, code, message, field string) (protocol.ResultEnvelope, error) {
	var fieldPtr *string
	if field != "" {
		fieldPtr = &field
	}

	return c.buildResult(ctx, op, protocol.StatusRejected, c.currentObservedVersionOrZero(op.ResourceID), c.currentGenerationIDOrNone(op.ResourceID),
		[]protocol.ResultError{{Code: code, Message: message, Field: fieldPtr}})
}

// failed reports a real, well-formed operational failure (a rejected DDL
// statement, a connection failure) as protocol.StatusFailed -- never a bare
// Go error. There is no rollback attempt: see this file's own package/Apply
// doc comments for why.
func (c *MariaDBCapability) failed(ctx context.Context, op protocol.OperationEnvelope, code, message string) (protocol.ResultEnvelope, error) {
	return c.buildResult(ctx, op, protocol.StatusFailed, c.currentObservedVersionOrZero(op.ResourceID), c.currentGenerationIDOrNone(op.ResourceID),
		[]protocol.ResultError{{Code: code, Message: message}})
}

// buildResult always attempts to recompute the current live digest fresh via
// a real query against the tenant instance: even a rejected or failed result
// must carry a schema-valid observed_state_digest, and the current live
// state is always well-defined regardless of this operation's outcome. If
// that fresh query itself fails or cannot be attempted (e.g. a malformed
// payload that never decoded to a usable database_name/database_user), the
// last-known generation's own recorded digest is used instead rather than
// surfacing a second, confusing failure inside a failure/rejection path;
// zeroDigest if there is no generation history at all yet either.
func (c *MariaDBCapability) buildResult(ctx context.Context, op protocol.OperationEnvelope, status protocol.Status, observedVersion int, generationID string, errs []protocol.ResultError) (protocol.ResultEnvelope, error) {
	digest := c.currentDigestOrFallback(ctx, op)

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
	}, nil
}

// currentDigestOrFallback attempts a fresh live-state query (best-effort: a
// payload decode failure means op.Payload may not even carry a usable
// database_name/database_user, in which case there is nothing meaningful to
// query, so the fallback path below is used instead).
func (c *MariaDBCapability) currentDigestOrFallback(ctx context.Context, op protocol.OperationEnvelope) string {
	if payload, verr := ParsePayload(op.Payload); verr == nil {
		if digest, err := computeDigest(ctx, c.cfg, payload.DatabaseName, payload.DatabaseUser); err == nil {
			return digest
		}
	}

	if n, ok, err := c.store.CurrentGeneration(op.ResourceID); err == nil && ok {
		if manifest, err := c.store.ReadManifest(op.ResourceID, n); err == nil {
			return manifest.Digest
		}
	}

	return zeroDigest
}

// currentGenerationIDOrNone reports resourceID's current generation as a
// string, or "none" if this node has no generation history for it at all.
func (c *MariaDBCapability) currentGenerationIDOrNone(resourceID string) string {
	n, ok, err := c.store.CurrentGeneration(resourceID)
	if err != nil || !ok {
		return "none"
	}

	return strconv.Itoa(n)
}

func (c *MariaDBCapability) currentObservedVersionOrZero(resourceID string) int {
	n, ok, err := c.store.CurrentGeneration(resourceID)
	if err != nil || !ok {
		return 0
	}

	manifest, err := c.store.ReadManifest(resourceID, n)
	if err != nil {
		return 0
	}

	return manifest.DesiredStateVersion
}
