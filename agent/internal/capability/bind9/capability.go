// Package bind9 implements the dns.bind9.v1 capability: the real, disk- and
// process-touching counterpart to internal/capability/fake, mirroring how
// internal/capability/nginx implements web.nginx.v1. See payload.go for the
// request shape and validation, template.go and templates/* for zone-file
// and stanza rendering, zonefile.go for the per-generation zone data file,
// validate.go for the dotfile-staging + synthetic-config `named-checkconf -z`
// harness, fragment.Dir for the atomic rename, reload.go for the rndc reload
// command and DNS health check, and this file for wiring all six protocol
// operations through one shared internal pipeline.
package bind9

import (
	"context"
	"fmt"
	"strconv"

	"github.com/mikho/LESta/agent/internal/capability/fragment"
	"github.com/mikho/LESta/agent/internal/generation"
	"github.com/mikho/LESta/agent/internal/idempotency"
	"github.com/mikho/LESta/agent/internal/opctx"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// Bind9Capability implements protocol.Capability for dns.bind9.v1.
type Bind9Capability struct {
	cfg      Config
	store    *generation.Store
	receipts *idempotency.Store
}

// New returns a Bind9Capability rooted at cfg's paths.
func New(cfg Config) *Bind9Capability {
	return &Bind9Capability{
		cfg:      cfg,
		store:    generation.New(cfg.StateRoot + "/zones"),
		receipts: idempotency.New(),
	}
}

// dir is the live fragment directory.
func (c *Bind9Capability) dir() fragment.Dir {
	return fragment.Dir{Path: c.cfg.LiveDir, Noun: "fragment"}
}

// results shapes this capability's result envelopes.
func (c *Bind9Capability) results() fragment.Results {
	return fragment.Results{Store: c.store, LiveDir: c.cfg.LiveDir}
}

// Apply implements protocol.Capability.
func (c *Bind9Capability) Apply(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
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
		result, err = c.applyGeneration(ctx, op, false)
	case protocol.OperationUpdate, protocol.OperationSuspend, protocol.OperationUnsuspend:
		result, err = c.applyGeneration(ctx, op, true)
	case protocol.OperationDelete:
		result, err = c.applyDelete(ctx, op)
	case protocol.OperationObserve:
		result, err = c.observe(ctx, op)
	default:
		result, err = c.results().Rejected(op, "unsupported_operation", fmt.Sprintf("operation %q is not supported", op.Operation), "")
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
// unsuspend all funnel through. They differ only in:
//   - requirePrior: create requires no prior generation; the other three
//     require one.
//   - whether payload.Suspended is set: a pure function of the payload,
//     never of the requested operation's name, so suspend and unsuspend are
//     just "apply this payload, whose Suspended happens to be true/false"
//     like any update. Unlike nginx (which renders an alternate
//     maintenance-page template for a suspended resource), DNS has no
//     equivalent to serving a maintenance page: a suspended zone's stanza is
//     simply absent from LiveDir, the same code shape as delete. That branch
//     is applySuspendedGeneration, below.
func (c *Bind9Capability) applyGeneration(ctx context.Context, op protocol.OperationEnvelope, requirePrior bool) (protocol.ResultEnvelope, error) {
	payload, verr := ParsePayload(op.Payload)
	if verr != nil {
		return c.results().RejectedFromValidationError(op, verr)
	}

	hasCurrent, err := c.store.HasCurrent(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if requirePrior && !hasCurrent {
		return c.results().Rejected(op, "unknown_resource", "no prior generation exists for this resource on this node", "")
	}
	if !requirePrior && hasCurrent {
		return c.results().Rejected(op, "resource_already_exists", "a generation already exists for this resource; use update instead of create", "")
	}

	n, err := c.store.NextGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if payload.Suspended {
		return c.applySuspendedGeneration(ctx, op, requirePrior, n)
	}

	zoneContent, err := renderZoneData(op.ResourceID, payload, c.cfg, n)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.writeZoneData(op.ResourceID, n, zoneContent); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	stanzaContent, err := renderStanza(op.ResourceID, payload.Domain, c.zoneDataPath(op.ResourceID, n))
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	stagingPath, err := c.dir().WriteStaging(op.ResourceID, stanzaContent)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if verr := c.validateCandidate(ctx, op.ResourceID, stagingPath); verr != nil {
		c.dir().DiscardStaging(op.ResourceID)
		c.discardZoneData(op.ResourceID, n)

		return c.results().RejectedFromValidationError(op, verr)
	}

	if err := c.dir().ActivateLive(op.ResourceID); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	digest, err := generation.ComputeDigest(c.cfg.LiveDir)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.store.Activate(op.ResourceID, n, stanzaContent, false, digest, op.DesiredStateVersion); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.writeGenerationMeta(op.ResourceID, n, payload); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if reloadErr := c.reload(ctx); reloadErr != nil {
		return c.recoverFromFailure(ctx, op, requirePrior, "reload_failed", reloadErr.Error())
	}

	if healthErr := c.waitHealthy(ctx, payload.Domain, op.ResourceID); healthErr != nil {
		return c.recoverFromFailure(ctx, op, requirePrior, "health_check_failed", healthErr.Error())
	}

	return c.results().Build(op, protocol.StatusApplied, op.DesiredStateVersion, strconv.Itoa(n), nil)
}

// applySuspendedGeneration handles the payload.Suspended branch of
// applyGeneration: no zone data is rendered and no stanza is staged, this
// resource's own generation history simply records a deletion for this
// generation, exactly like applyDelete. This still goes through
// requirePrior's own create-vs-update semantics (already checked by the
// caller): a zone CAN be created already suspended (requirePrior==false, its
// very first generation recorded deleted=true), the same as an existing zone
// being suspended (requirePrior==true).
func (c *Bind9Capability) applySuspendedGeneration(ctx context.Context, op protocol.OperationEnvelope, requirePrior bool, n int) (protocol.ResultEnvelope, error) {
	if verr := c.validateCandidate(ctx, op.ResourceID, ""); verr != nil {
		return c.results().RejectedFromValidationError(op, verr)
	}

	if err := c.dir().RemoveLive(op.ResourceID); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	digest, err := generation.ComputeDigest(c.cfg.LiveDir)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.store.Activate(op.ResourceID, n, nil, true, digest, op.DesiredStateVersion); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if reloadErr := c.reload(ctx); reloadErr != nil {
		return c.recoverFromFailure(ctx, op, requirePrior, "reload_failed", reloadErr.Error())
	}

	if healthErr := c.waitHealthyGeneric(ctx); healthErr != nil {
		return c.recoverFromFailure(ctx, op, requirePrior, "health_check_failed", healthErr.Error())
	}

	return c.results().Build(op, protocol.StatusApplied, op.DesiredStateVersion, strconv.Itoa(n), nil)
}

// applyDelete goes through the same rollback-capable pipeline as
// update/suspend/unsuspend (validate a config with this resource's stanza
// omitted, then os.Remove, reload, a generic health probe since there's no
// more per-zone health to check), so a reload failure after deletion still
// has a way back.
func (c *Bind9Capability) applyDelete(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	if _, verr := ParsePayload(op.Payload); verr != nil {
		return c.results().RejectedFromValidationError(op, verr)
	}

	hasCurrent, err := c.store.HasCurrent(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}
	if !hasCurrent {
		return c.results().Rejected(op, "unknown_resource", "no prior generation exists for this resource on this node", "")
	}

	if verr := c.validateCandidate(ctx, op.ResourceID, ""); verr != nil {
		return c.results().RejectedFromValidationError(op, verr)
	}

	if err := c.dir().RemoveLive(op.ResourceID); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	digest, err := generation.ComputeDigest(c.cfg.LiveDir)
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

	if reloadErr := c.reload(ctx); reloadErr != nil {
		return c.recoverFromFailure(ctx, op, true, "reload_failed", reloadErr.Error())
	}

	if healthErr := c.waitHealthyGeneric(ctx); healthErr != nil {
		return c.recoverFromFailure(ctx, op, true, "health_check_failed", healthErr.Error())
	}

	return c.results().Build(op, protocol.StatusApplied, op.DesiredStateVersion, strconv.Itoa(n), nil)
}

// observe is read-only: it recomputes the whole-LiveDir digest and compares
// it to the recorded generation's manifest. It never renders, validates,
// activates, or reloads anything.
func (c *Bind9Capability) observe(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	n, ok, err := c.store.CurrentGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}
	if !ok {
		return c.results().Rejected(op, "unknown_resource", "no generation history exists for this resource on this node", "")
	}

	manifest, err := c.store.ReadManifest(op.ResourceID, n)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	liveDigest, err := generation.ComputeDigest(c.cfg.LiveDir)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if liveDigest == manifest.Digest {
		return c.results().Build(op, protocol.StatusApplied, manifest.DesiredStateVersion, strconv.Itoa(n), nil)
	}

	return c.results().Build(op, protocol.StatusDegraded, manifest.DesiredStateVersion, strconv.Itoa(n), []protocol.ResultError{
		{Code: "drift_detected", Message: fmt.Sprintf("live digest %s does not match generation %d's recorded digest %s", liveDigest, n, manifest.Digest)},
	})
}

// recoverFromFailure implements the ADR's failure-semantics table for
// update/suspend/unsuspend/delete (requirePrior is always true for these):
// restore the previous generation, reload, re-check health. Degraded if that
// rollback itself comes up healthy (last-known-good is serving, the
// requested change didn't take effect); failed only if even the rollback's
// own health check (or its own validation, or its own reload) fails.
//
// create (requirePrior == false) has no prior generation to fall back to, so
// the result is always failed, never degraded, and no rollback is attempted.
//
// Restoring a real (non-deleted) previous generation does NOT byte-copy that
// generation's stored stanza forward, unlike nginx's equivalent (safe there
// because a rendered vhost fragment never embeds its own generation number).
// Here the stanza's `file` directive points at a *specific* generation's
// zone.db path; byte-copying an old generation's stanza into a new
// generation slot would leave the live config pointing backward at that old
// generation's directory, which generation.Store's own pruning (Keep, default
// 5) can delete once enough later operations on this resource push it out of
// the retention window — breaking the next unrelated reload, however much
// later that happens. Instead, this reads the previous generation's
// payload.json sidecar and RE-RENDERS a fresh zone.db and stanza, targeting
// the NEW generation number NextGeneration just gave this rollback attempt,
// so the restored generation is fully self-contained.
func (c *Bind9Capability) recoverFromFailure(ctx context.Context, op protocol.OperationEnvelope, requirePrior bool, code, message string) (protocol.ResultEnvelope, error) {
	ctx, cancel := opctx.Rollback(ctx)
	defer cancel()

	if !requirePrior {
		return c.results().Build(op, protocol.StatusFailed, op.DesiredStateVersion, c.results().CurrentGenerationIDOrNone(op.ResourceID),
			[]protocol.ResultError{{Code: code, Message: message}})
	}

	prevN, ok, err := c.store.PreviousGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}
	if !ok {
		return c.results().Build(op, protocol.StatusFailed, op.DesiredStateVersion, c.results().CurrentGenerationIDOrNone(op.ResourceID),
			[]protocol.ResultError{{Code: code, Message: message + "; no previous generation available to roll back to"}})
	}

	_, prevDeleted, err := c.store.ReadContent(op.ResourceID, prevN)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	n, err := c.store.NextGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	failed := func(reason string) (protocol.ResultEnvelope, error) {
		return c.results().Build(op, protocol.StatusFailed, op.DesiredStateVersion, c.results().CurrentGenerationIDOrNone(op.ResourceID),
			[]protocol.ResultError{{Code: code, Message: message + "; rollback also failed: " + reason}})
	}

	var (
		candidatePath string
		stanzaContent []byte
		prevPayload   Payload
	)

	if !prevDeleted {
		var perr error

		prevPayload, perr = c.readGenerationMeta(op.ResourceID, prevN)
		if perr != nil {
			return protocol.ResultEnvelope{}, perr
		}

		zoneContent, rerr := renderZoneData(op.ResourceID, prevPayload, c.cfg, n)
		if rerr != nil {
			return protocol.ResultEnvelope{}, rerr
		}

		if werr := c.writeZoneData(op.ResourceID, n, zoneContent); werr != nil {
			return protocol.ResultEnvelope{}, werr
		}

		stanzaContent, rerr = renderStanza(op.ResourceID, prevPayload.Domain, c.zoneDataPath(op.ResourceID, n))
		if rerr != nil {
			return protocol.ResultEnvelope{}, rerr
		}

		var serr error

		candidatePath, serr = c.dir().WriteStaging(op.ResourceID, stanzaContent)
		if serr != nil {
			return protocol.ResultEnvelope{}, serr
		}
	}

	if verr := c.validateCandidate(ctx, op.ResourceID, candidatePath); verr != nil {
		if candidatePath != "" {
			c.dir().DiscardStaging(op.ResourceID)
			c.discardZoneData(op.ResourceID, n)
		}

		return failed(verr.Error())
	}

	if prevDeleted {
		if err := c.dir().RemoveLive(op.ResourceID); err != nil {
			return protocol.ResultEnvelope{}, err
		}
	} else if err := c.dir().ActivateLive(op.ResourceID); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	digest, err := generation.ComputeDigest(c.cfg.LiveDir)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.store.Activate(op.ResourceID, n, stanzaContent, prevDeleted, digest, op.DesiredStateVersion); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if !prevDeleted {
		if err := c.writeGenerationMeta(op.ResourceID, n, prevPayload); err != nil {
			return protocol.ResultEnvelope{}, err
		}
	}

	if reloadErr := c.reload(ctx); reloadErr != nil {
		return failed("reload: " + reloadErr.Error())
	}

	var healthErr error
	if prevDeleted {
		// Rolling back to a previous generation that was itself a deletion
		// (or a suspension: represented identically) needs no per-zone
		// content check, only that named itself is still healthy.
		healthErr = c.waitHealthyGeneric(ctx)
	} else {
		healthErr = c.waitHealthy(ctx, prevPayload.Domain, op.ResourceID)
	}

	if healthErr != nil {
		return failed("health check: " + healthErr.Error())
	}

	return c.results().Build(op, protocol.StatusDegraded, op.DesiredStateVersion, strconv.Itoa(n),
		[]protocol.ResultError{{Code: code, Message: message}})
}
