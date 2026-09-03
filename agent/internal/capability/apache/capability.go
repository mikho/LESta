// Package apache implements the web.apache.v1 capability: the real, disk- and
// process-touching counterpart to internal/capability/fake, mirroring how
// internal/capability/nginx implements web.nginx.v1. See payload.go for the
// request shape and hostname validation, template.go and templates/* for
// rendering, content.go for the per-generation mod_asis content file and the
// static modules-enabling fragment, validate.go for the dotfile-staging +
// synthetic-config `apache2 -t` harness, activate.go for the atomic rename,
// reload.go for the reload command and HTTP health check, and this file for
// wiring all six protocol operations through one shared internal pipeline.
//
// Unlike nginx's rendered vhost (which never embeds a generation number, so a
// prior generation's stored bytes can be replayed byte-for-byte on rollback),
// Apache's vhost fragment points its DocumentRoot at its OWN generation's
// content directory -- the same generation-specific-path property bind9's
// stanza has (see bind9/capability.go's recoverFromFailure doc comment). A
// rollback here therefore re-renders fresh content and a fresh vhost fragment
// targeting the NEW generation number a rollback attempt is given, exactly
// like bind9, never byte-copying a previous generation's fragment forward:
// doing so would leave the live vhost pointing at a generation directory that
// generation.Store's own pruning (Keep, default 5) could delete once enough
// later operations on this resource push it out of the retention window.
package apache

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

// ApacheCapability implements protocol.Capability for web.apache.v1.
type ApacheCapability struct {
	cfg      Config
	store    *generation.Store
	receipts *idempotency.Store
}

// New returns an ApacheCapability rooted at cfg's paths. cfg.StateRoot/domains
// is where generation.Store nests per-resource history, reconciling the ADR's
// general `/var/lib/lesta/generations/<service>/<generation>/` prose with
// apache's own manifest, whose only declared owned_roots are
// /etc/apache2/lesta.d and /var/lib/lesta/apache: no shared
// /var/lib/lesta/generations/ root exists anywhere, so history nests under
// apache's own owned root instead, exactly like nginx's own reconciliation.
func New(cfg Config) *ApacheCapability {
	return &ApacheCapability{
		cfg:      cfg,
		store:    generation.New(cfg.StateRoot + "/domains"),
		receipts: idempotency.New(),
	}
}

// Apply implements protocol.Capability.
func (c *ApacheCapability) Apply(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	ctx, cancel := context.WithDeadline(ctx, op.Deadline)
	defer cancel()

	// observe never applies anything, so it is never deduplicated: there is no
	// side effect a duplicate observe could redundantly redo.
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

// vhostDataFor builds the substitution set for generation n of resourceID from
// an already-validated payload.
func (c *ApacheCapability) vhostDataFor(resourceID string, payload Payload, n int) vhostData {
	return vhostData{
		ResourceID:       resourceID,
		Domain:           payload.Domain,
		Aliases:          payload.Aliases,
		IPAddress:        payload.IPAddress,
		Port:             c.cfg.Port,
		ContentDir:       c.store.GenerationDir(resourceID, n),
		AcmeChallengeDir: c.cfg.AcmeChallengeDir,
		CertificatePath:  payload.SSL.CertificatePath,
		PrivateKeyPath:   payload.SSL.PrivateKeyPath,
		SSLPort:          c.cfg.SSLPort,
	}
}

// expectedMarkerFor reports the marker a health check should look for in
// resourceID's currently-rendered content: the per-resource default marker,
// unless payload.Suspended selected the fixed suspended maintenance page.
func expectedMarkerFor(resourceID string, suspended bool) string {
	if suspended {
		return suspendedMarker
	}

	return vhostData{ResourceID: resourceID}.marker()
}

// applyGeneration is the one internal pipeline create/update/suspend/unsuspend
// all funnel through. They differ only in:
//   - requirePrior: create requires no prior generation; the other three
//     require one.
//   - which template payload.Suspended selects: a pure function of the
//     payload, never of the requested operation's name, so suspend and
//     unsuspend are just "apply this payload, whose Suspended happens to be
//     true/false" like any update.
func (c *ApacheCapability) applyGeneration(ctx context.Context, op protocol.OperationEnvelope, requirePrior bool) (protocol.ResultEnvelope, error) {
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

	n, err := c.store.NextGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	data := c.vhostDataFor(op.ResourceID, payload, n)

	contentBytes, err := renderContent(data, payload.Suspended)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.writeContent(op.ResourceID, n, contentBytes); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	vhostBytes, err := renderVhost(data, payload.Suspended)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := ensureModulesFragment(c.cfg.LiveDir, c.cfg.SSLPort); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	stagingPath, err := c.writeStaging(op.ResourceID, vhostBytes)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if verr := c.validateCandidate(ctx, op.ResourceID, stagingPath); verr != nil {
		c.discardStaging(op.ResourceID)
		c.discardContent(op.ResourceID, n)

		return c.rejectedFromValidationError(op, verr)
	}

	if err := c.activateLive(op.ResourceID); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	digest, err := generation.ComputeDigest(c.cfg.LiveDir)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.store.Activate(op.ResourceID, n, vhostBytes, false, digest, op.DesiredStateVersion); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.writeGenerationMeta(op.ResourceID, n, payload); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if reloadErr := c.reload(ctx); reloadErr != nil {
		return c.recoverFromFailure(ctx, op, requirePrior, "reload_failed", reloadErr.Error())
	}

	expectedMarker := expectedMarkerFor(op.ResourceID, payload.Suspended)

	if healthErr := c.waitHealthy(ctx, payload.IPAddress, c.cfg.Port, payload.Domain, expectedMarker); healthErr != nil {
		return c.recoverFromFailure(ctx, op, requirePrior, "health_check_failed", healthErr.Error())
	}

	return c.buildResult(op, protocol.StatusApplied, op.DesiredStateVersion, strconv.Itoa(n), nil)
}

// applyDelete goes through the same rollback-capable pipeline as
// update/suspend/unsuspend (validate a config with this resource's fragment
// omitted, then os.Remove, reload, a generic health probe since there's no more
// per-vhost health to check), so a reload failure after deletion still has a
// way back.
func (c *ApacheCapability) applyDelete(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
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

	if err := ensureModulesFragment(c.cfg.LiveDir, c.cfg.SSLPort); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if verr := c.validateCandidate(ctx, op.ResourceID, ""); verr != nil {
		return c.rejectedFromValidationError(op, verr)
	}

	if err := c.removeLive(op.ResourceID); err != nil {
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

	if healthErr := c.waitHealthyGeneric(ctx, payload.IPAddress, c.cfg.Port); healthErr != nil {
		return c.recoverFromFailure(ctx, op, true, "health_check_failed", healthErr.Error())
	}

	return c.buildResult(op, protocol.StatusApplied, op.DesiredStateVersion, strconv.Itoa(n), nil)
}

// observe is read-only: it recomputes the whole-LiveDir digest and compares it
// to the recorded generation's manifest. It never renders, validates,
// activates, or reloads anything.
func (c *ApacheCapability) observe(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
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

	liveDigest, err := generation.ComputeDigest(c.cfg.LiveDir)
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

// recoverFromFailure implements the ADR's failure-semantics table for
// update/suspend/unsuspend/delete (requirePrior is always true for these):
// re-render the previous generation's payload fresh, targeting the NEW
// generation number this rollback attempt is given (never byte-copying the
// previous generation's stored fragment forward -- see this file's own top
// comment for why that would be unsafe here, unlike nginx), reload, re-check
// health. Degraded if that rollback itself comes up healthy (last-known-good
// is serving, the requested change didn't take effect); failed only if even
// the rollback's own health check (or its own validation, or its own reload)
// fails.
//
// create (requirePrior == false) has no prior generation to fall back to, so
// the result is always failed, never degraded, and no rollback is attempted:
// there is nothing to roll back to. The live fragment this attempt already
// activated (if it got that far) is left as-is; there is no "prior good state"
// to restore it to, only the fact that the attempt failed.
func (c *ApacheCapability) recoverFromFailure(ctx context.Context, op protocol.OperationEnvelope, requirePrior bool, code, message string) (protocol.ResultEnvelope, error) {
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

	_, prevDeleted, err := c.store.ReadContent(op.ResourceID, prevN)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	n, err := c.store.NextGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	failed := func(reason string) (protocol.ResultEnvelope, error) {
		return c.buildResult(op, protocol.StatusFailed, op.DesiredStateVersion, c.currentGenerationIDOrNone(op.ResourceID),
			[]protocol.ResultError{{Code: code, Message: message + "; rollback also failed: " + reason}})
	}

	var (
		candidatePath string
		vhostBytes    []byte
		prevPayload   Payload
	)

	if !prevDeleted {
		var perr error

		prevPayload, perr = c.readGenerationMeta(op.ResourceID, prevN)
		if perr != nil {
			return protocol.ResultEnvelope{}, perr
		}

		data := c.vhostDataFor(op.ResourceID, prevPayload, n)

		contentBytes, rerr := renderContent(data, prevPayload.Suspended)
		if rerr != nil {
			return protocol.ResultEnvelope{}, rerr
		}

		if werr := c.writeContent(op.ResourceID, n, contentBytes); werr != nil {
			return protocol.ResultEnvelope{}, werr
		}

		vhostBytes, rerr = renderVhost(data, prevPayload.Suspended)
		if rerr != nil {
			return protocol.ResultEnvelope{}, rerr
		}

		if err := ensureModulesFragment(c.cfg.LiveDir, c.cfg.SSLPort); err != nil {
			return protocol.ResultEnvelope{}, err
		}

		var serr error

		candidatePath, serr = c.writeStaging(op.ResourceID, vhostBytes)
		if serr != nil {
			return protocol.ResultEnvelope{}, serr
		}
	}

	if verr := c.validateCandidate(ctx, op.ResourceID, candidatePath); verr != nil {
		if candidatePath != "" {
			c.discardStaging(op.ResourceID)
			c.discardContent(op.ResourceID, n)
		}

		return failed(verr.Error())
	}

	if prevDeleted {
		if err := c.removeLive(op.ResourceID); err != nil {
			return protocol.ResultEnvelope{}, err
		}
	} else if err := c.activateLive(op.ResourceID); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	digest, err := generation.ComputeDigest(c.cfg.LiveDir)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.store.Activate(op.ResourceID, n, vhostBytes, prevDeleted, digest, op.DesiredStateVersion); err != nil {
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
		// Rolling back a failed delete restores the resource; the failed
		// delete's own operation envelope still carries this resource's
		// ip_address (delete's payload is the domain's full
		// toProvisioningPayload(), same as every other operation), so a
		// generic probe against it is meaningful even though we have no
		// "deleted generation" payload of our own to check content against.
		origPayload, perr := ParsePayload(op.Payload)
		if perr != nil {
			return protocol.ResultEnvelope{}, perr
		}

		healthErr = c.waitHealthyGeneric(ctx, origPayload.IPAddress, c.cfg.Port)
	} else {
		expectedMarker := expectedMarkerFor(op.ResourceID, prevPayload.Suspended)

		healthErr = c.waitHealthy(ctx, prevPayload.IPAddress, c.cfg.Port, prevPayload.Domain, expectedMarker)
	}

	if healthErr != nil {
		return failed("health check: " + healthErr.Error())
	}

	return c.buildResult(op, protocol.StatusDegraded, op.DesiredStateVersion, strconv.Itoa(n),
		[]protocol.ResultError{{Code: code, Message: message}})
}

func (c *ApacheCapability) rejectedFromValidationError(op protocol.OperationEnvelope, err error) (protocol.ResultEnvelope, error) {
	var ve *ValidationError
	if errors.As(err, &ve) {
		return c.rejected(op, ve.Code, ve.Message, ve.Field)
	}

	return protocol.ResultEnvelope{}, err
}

func (c *ApacheCapability) rejected(op protocol.OperationEnvelope, code, message, field string) (protocol.ResultEnvelope, error) {
	var fieldPtr *string
	if field != "" {
		fieldPtr = &field
	}

	return c.buildResult(op, protocol.StatusRejected, c.currentObservedVersionOrZero(op.ResourceID), c.currentGenerationIDOrNone(op.ResourceID),
		[]protocol.ResultError{{Code: code, Message: message, Field: fieldPtr}})
}

// buildResult always recomputes the current whole-LiveDir digest fresh: even a
// rejected or failed result must carry a schema-valid observed_state_digest, and
// the current live state is always well-defined regardless of this operation's
// outcome.
func (c *ApacheCapability) buildResult(op protocol.OperationEnvelope, status protocol.Status, observedVersion int, generationID string, errs []protocol.ResultError) (protocol.ResultEnvelope, error) {
	digest, err := generation.ComputeDigest(c.cfg.LiveDir)
	if err != nil {
		return protocol.ResultEnvelope{}, fmt.Errorf("computing observed digest: %w", err)
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

// currentGenerationIDOrNone reports resourceID's current generation as a
// string, or "none" if this node has no generation history for it at all (e.g.
// a rejection that never got far enough to establish one).
func (c *ApacheCapability) currentGenerationIDOrNone(resourceID string) string {
	n, ok, err := c.store.CurrentGeneration(resourceID)
	if err != nil || !ok {
		return "none"
	}

	return strconv.Itoa(n)
}

func (c *ApacheCapability) currentObservedVersionOrZero(resourceID string) int {
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
