// Package phpfpm implements the web.php-fpm.v1 capability: one pool per
// WebDomain (resource_id), running as that domain's own account's dedicated
// OS identity (system.account-identity.v1's own lesta-t{account_id}),
// mirroring nginx's own generation/staging/validate/activate/reload
// pipeline shape (internal/capability/nginx). See payload.go for the
// request shape, template.go and templates/pool.conf.tmpl for rendering,
// validate.go for the dotfile-staging + synthetic-config `php-fpm -t`
// harness, activate.go for the atomic rename, reload.go for the reload
// command and unix-socket health check, and this file for wiring all six
// protocol operations through one shared internal pipeline.
//
// One real structural difference from every other capability in this
// module: a resource's own php_version can change between generations
// (an update may move a domain from PHP 8.1 to 8.3), which also moves its
// live pool fragment from one version's own poolDir to another. This
// package tracks each generation's own recorded version in its meta
// sidecar (meta.go) so a rollback re-activates the exact prior version's
// own poolDir, never the new attempt's. Cleaning up a now-stale fragment
// left behind in the OLD version's poolDir after a successful version
// change is deliberately best-effort and non-fatal: the new pool is
// already live and healthy by that point, and a harmless leftover pool
// file (nothing connects to its socket anymore, since the domain's own
// rendered vhost already points at the new version's socket) is a low-
// severity cleanup gap, not a correctness or security one.
package phpfpm

import (
	"context"
	"errors"
	"fmt"
	"strconv"
	"time"

	"github.com/mikho/LESta/agent/internal/generation"
	"github.com/mikho/LESta/agent/internal/idempotency"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// PhpFpmCapability implements protocol.Capability for web.php-fpm.v1.
type PhpFpmCapability struct {
	cfg      Config
	store    *generation.Store
	receipts *idempotency.Store
}

// New returns a PhpFpmCapability rooted at cfg.StateRoot/domains, mirroring
// nginx's own generation.Store placement exactly.
func New(cfg Config) *PhpFpmCapability {
	return &PhpFpmCapability{
		cfg:      cfg,
		store:    generation.New(cfg.StateRoot + "/domains"),
		receipts: idempotency.New(),
	}
}

// Apply implements protocol.Capability.
func (c *PhpFpmCapability) Apply(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	ctx, cancel := context.WithDeadline(ctx, op.Deadline)
	defer cancel()

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
		result, err = c.applyGeneration(ctx, op, false)
	case protocol.OperationUpdate, protocol.OperationSuspend, protocol.OperationUnsuspend:
		result, err = c.applyGeneration(ctx, op, true)
	case protocol.OperationDelete:
		result, err = c.applyDelete(ctx, op)
	case protocol.OperationObserve:
		result, err = c.observe(ctx, op)
	default:
		result, err = c.rejected(op, "unsupported_operation", fmt.Sprintf("operation %q is not supported", op.Operation), "")
	}

	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if op.Operation != protocol.OperationObserve {
		c.receipts.Record(op.IdempotencyKey, result)
	}

	return result, nil
}

// applyGeneration is the one internal pipeline create/update/suspend/
// unsuspend all funnel through. requirePrior mirrors nginx's own flag
// exactly: create must be the first generation for this resource; the
// other three must not be.
func (c *PhpFpmCapability) applyGeneration(ctx context.Context, op protocol.OperationEnvelope, requirePrior bool) (protocol.ResultEnvelope, error) {
	payload, verr := ParsePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(op, verr)
	}

	hasCurrent, err := c.store.HasCurrent(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if requirePrior && !hasCurrent {
		return c.rejected(op, "unknown_resource", "no prior generation exists for this resource on this node", "")
	}
	if !requirePrior && hasCurrent {
		return c.rejected(op, "resource_already_exists", "a generation already exists for this resource; use update instead of create", "")
	}

	// Learn the previously-live version (if any) before doing anything else,
	// so a version change can clean up the old poolDir once the new one is
	// confirmed healthy, and so a later rollback of THIS attempt knows which
	// version's own poolDir to restore into rather than assuming the new
	// attempt's version.
	var oldVersion string
	if hasCurrent {
		prevN, ok, perr := c.store.CurrentGeneration(op.ResourceID)
		if perr != nil {
			return protocol.ResultEnvelope{}, perr
		}
		if ok {
			prevPayload, merr := c.readGenerationMeta(op.ResourceID, prevN)
			if merr != nil {
				return protocol.ResultEnvelope{}, merr
			}
			oldVersion = prevPayload.PhpVersion
		}
	}

	n, err := c.store.NextGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	docroot := c.cfg.docroot(payload.AccountUsername, op.ResourceID)

	content, err := renderPool(poolData{
		ResourceID:      op.ResourceID,
		AccountUsername: payload.AccountUsername,
		SocketPath:      c.cfg.socketPath(payload.PhpVersion, op.ResourceID),
		Docroot:         docroot,
	})
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	stagingPath, err := c.writeStaging(payload.PhpVersion, op.ResourceID, content)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if verr := c.validateCandidate(ctx, payload.PhpVersion, op.ResourceID, stagingPath); verr != nil {
		c.discardStaging(payload.PhpVersion, op.ResourceID)

		return c.rejectedFromValidationError(op, verr)
	}

	if err := c.activateLive(payload.PhpVersion, op.ResourceID); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	digest, err := generation.ComputeDigest(c.cfg.poolDir(payload.PhpVersion))
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.store.Activate(op.ResourceID, n, content, false, digest, op.DesiredStateVersion); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.writeGenerationMeta(op.ResourceID, n, payload); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if reloadErr := c.reload(ctx, payload.PhpVersion); reloadErr != nil {
		return c.recoverFromFailure(ctx, op, requirePrior, oldVersion, "reload_failed", reloadErr.Error())
	}

	if healthErr := c.waitHealthy(ctx, payload.PhpVersion, op.ResourceID); healthErr != nil {
		return c.recoverFromFailure(ctx, op, requirePrior, oldVersion, "health_check_failed", healthErr.Error())
	}

	// Best-effort, non-fatal cleanup of a version change's own now-stale old
	// pool fragment -- see this package's own doc comment for why this is
	// deliberately never allowed to fail the operation overall.
	if oldVersion != "" && oldVersion != payload.PhpVersion {
		_ = c.removeLive(oldVersion, op.ResourceID)
		_ = c.reload(ctx, oldVersion)
	}

	return c.buildResult(op, protocol.StatusApplied, op.DesiredStateVersion, strconv.Itoa(n), nil)
}

// applyDelete goes through the same rollback-capable pipeline as
// update/suspend/unsuspend (validate a config with this resource's own
// fragment omitted, then os.Remove, reload, a generic health probe since
// there's no more per-resource socket to check), so a reload failure after
// deletion still has a way back.
func (c *PhpFpmCapability) applyDelete(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParsePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(op, verr)
	}

	hasCurrent, err := c.store.HasCurrent(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}
	if !hasCurrent {
		return c.rejected(op, "unknown_resource", "no prior generation exists for this resource on this node", "")
	}

	if verr := c.validateCandidate(ctx, payload.PhpVersion, op.ResourceID, ""); verr != nil {
		return c.rejectedFromValidationError(op, verr)
	}

	if err := c.removeLive(payload.PhpVersion, op.ResourceID); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	digest, err := generation.ComputeDigest(c.cfg.poolDir(payload.PhpVersion))
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	n, err := c.store.NextGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.store.Activate(op.ResourceID, n, nil, true, digest, op.DesiredStateVersion); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if reloadErr := c.reload(ctx, payload.PhpVersion); reloadErr != nil {
		return c.recoverFromFailure(ctx, op, true, "", "reload_failed", reloadErr.Error())
	}

	if healthErr := c.waitHealthyGeneric(ctx, payload.PhpVersion); healthErr != nil {
		return c.recoverFromFailure(ctx, op, true, "", "health_check_failed", healthErr.Error())
	}

	return c.buildResult(op, protocol.StatusApplied, op.DesiredStateVersion, strconv.Itoa(n), nil)
}

// observe is read-only: it recomputes this resource's own version-specific
// poolDir digest and compares it to the recorded generation's manifest. It
// never renders, validates, activates, or reloads anything.
func (c *PhpFpmCapability) observe(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	n, ok, err := c.store.CurrentGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}
	if !ok {
		return c.rejected(op, "unknown_resource", "no generation history exists for this resource on this node", "")
	}

	manifest, err := c.store.ReadManifest(op.ResourceID, n)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	payload, err := c.readGenerationMeta(op.ResourceID, n)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	liveDigest, err := generation.ComputeDigest(c.cfg.poolDir(payload.PhpVersion))
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if liveDigest == manifest.Digest {
		return c.buildResult(op, protocol.StatusApplied, manifest.DesiredStateVersion, strconv.Itoa(n), nil)
	}

	return c.buildResult(op, protocol.StatusDegraded, manifest.DesiredStateVersion, strconv.Itoa(n), []protocol.ResultError{
		{Code: "drift_detected", Message: fmt.Sprintf("live digest %s does not match generation %d's recorded digest %s", liveDigest, n, manifest.Digest)},
	})
}

// recoverFromFailure mirrors nginx's own rollback shape exactly (see that
// package's own doc comment for the full failure-semantics table),
// adjusted for the one real structural difference this package has: the
// previous generation's own live pool may live in a DIFFERENT version's
// poolDir than the attempt that just failed (oldVersion, resolved by the
// caller before this attempt ever touched anything). Rollback always
// re-activates into oldVersion's own poolDir, never the failed attempt's.
func (c *PhpFpmCapability) recoverFromFailure(ctx context.Context, op protocol.OperationEnvelope, requirePrior bool, oldVersion, code, message string) (protocol.ResultEnvelope, error) {
	if !requirePrior {
		return c.buildResult(op, protocol.StatusFailed, op.DesiredStateVersion, c.currentGenerationIDOrNone(op.ResourceID),
			[]protocol.ResultError{{Code: code, Message: message}})
	}

	prevN, ok, err := c.store.PreviousGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}
	if !ok {
		return c.buildResult(op, protocol.StatusFailed, op.DesiredStateVersion, c.currentGenerationIDOrNone(op.ResourceID),
			[]protocol.ResultError{{Code: code, Message: message + "; no previous generation available to roll back to"}})
	}

	prevContent, prevDeleted, err := c.store.ReadContent(op.ResourceID, prevN)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	failed := func(reason string) (protocol.ResultEnvelope, error) {
		return c.buildResult(op, protocol.StatusFailed, op.DesiredStateVersion, c.currentGenerationIDOrNone(op.ResourceID),
			[]protocol.ResultError{{Code: code, Message: message + "; rollback also failed: " + reason}})
	}

	var prevPayload Payload
	if !prevDeleted {
		prevPayload, err = c.readGenerationMeta(op.ResourceID, prevN)
		if err != nil {
			return protocol.ResultEnvelope{}, err
		}
	}

	rollbackVersion := oldVersion
	if rollbackVersion == "" {
		rollbackVersion = prevPayload.PhpVersion
	}

	var candidatePath string
	if !prevDeleted {
		candidatePath, err = c.writeStaging(rollbackVersion, op.ResourceID, prevContent)
		if err != nil {
			return protocol.ResultEnvelope{}, err
		}
	}

	if verr := c.validateCandidate(ctx, rollbackVersion, op.ResourceID, candidatePath); verr != nil {
		if candidatePath != "" {
			c.discardStaging(rollbackVersion, op.ResourceID)
		}

		return failed(verr.Error())
	}

	if prevDeleted {
		if err := c.removeLive(rollbackVersion, op.ResourceID); err != nil {
			return protocol.ResultEnvelope{}, err
		}
	} else if err := c.activateLive(rollbackVersion, op.ResourceID); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	digest, err := generation.ComputeDigest(c.cfg.poolDir(rollbackVersion))
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	n, err := c.store.NextGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.store.Activate(op.ResourceID, n, prevContent, prevDeleted, digest, op.DesiredStateVersion); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if !prevDeleted {
		if err := c.writeGenerationMeta(op.ResourceID, n, prevPayload); err != nil {
			return protocol.ResultEnvelope{}, err
		}
	}

	if reloadErr := c.reload(ctx, rollbackVersion); reloadErr != nil {
		return failed("reload: " + reloadErr.Error())
	}

	var healthErr error
	if prevDeleted {
		healthErr = c.waitHealthyGeneric(ctx, rollbackVersion)
	} else {
		healthErr = c.waitHealthy(ctx, rollbackVersion, op.ResourceID)
	}

	if healthErr != nil {
		return failed("health check: " + healthErr.Error())
	}

	return c.buildResult(op, protocol.StatusDegraded, op.DesiredStateVersion, strconv.Itoa(n),
		[]protocol.ResultError{{Code: code, Message: message}})
}

func (c *PhpFpmCapability) rejectedFromValidationError(op protocol.OperationEnvelope, err error) (protocol.ResultEnvelope, error) {
	var ve *ValidationError
	if errors.As(err, &ve) {
		return c.rejected(op, ve.Code, ve.Message, ve.Field)
	}

	return protocol.ResultEnvelope{}, err
}

func (c *PhpFpmCapability) rejected(op protocol.OperationEnvelope, code, message, field string) (protocol.ResultEnvelope, error) {
	var fieldPtr *string
	if field != "" {
		fieldPtr = &field
	}

	return c.buildResult(op, protocol.StatusRejected, c.currentObservedVersionOrZero(op.ResourceID), c.currentGenerationIDOrNone(op.ResourceID),
		[]protocol.ResultError{{Code: code, Message: message, Field: fieldPtr}})
}

// buildResult reports a fixed placeholder digest when this resource has no
// current generation at all yet (a rejected create never got far enough to
// know which version's own poolDir it would have used); otherwise the real
// current generation's own recorded version resolves which poolDir to
// digest fresh.
func (c *PhpFpmCapability) buildResult(op protocol.OperationEnvelope, status protocol.Status, observedVersion int, generationID string, errs []protocol.ResultError) (protocol.ResultEnvelope, error) {
	digest := "sha256:0000000000000000000000000000000000000000000000000000000000000000"

	if n, ok, err := c.store.CurrentGeneration(op.ResourceID); err == nil && ok {
		if payload, merr := c.readGenerationMeta(op.ResourceID, n); merr == nil {
			if d, derr := generation.ComputeDigest(c.cfg.poolDir(payload.PhpVersion)); derr == nil {
				digest = d
			}
		}
	}

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

func (c *PhpFpmCapability) currentGenerationIDOrNone(resourceID string) string {
	n, ok, err := c.store.CurrentGeneration(resourceID)
	if err != nil || !ok {
		return "none"
	}

	return strconv.Itoa(n)
}

func (c *PhpFpmCapability) currentObservedVersionOrZero(resourceID string) int {
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
