// Package identity implements the system.account-identity.v1 capability:
// real per-tenant-account Linux system users, one per (account, node) pair,
// created lazily the first time that account gets a cron job or a web
// domain on a node (see App\Actions\Provisioning\EnsuresAccountNodeIdentity),
// so scheduler.account-cron.v1 can run each account's own cron jobs under
// its own dedicated, non-root identity, and (Web Application Hosting Threat
// Model and Isolation Design.md step 2) so the same identity can log in over
// SFTP, chrooted to its own account tree.
//
// create/update both exec real external commands (useradd/sshd) and render
// real config, mirroring internal/capability/nginx's own staged-render/
// validate/atomically-activate/reload/health-check/rollback pipeline
// exactly: see template.go for the Match-block/authorized_keys rendering,
// validate.go for the synthetic-config `sshd -t` harness, activate.go for
// the atomic rename, chroot.go for the real ChrootDirectory tree, reload.go
// for the reload signal and TCP health check, and this file for wiring
// create/update/delete through one shared pipeline. delete still execs
// userdel directly (mirrored from this package's own pre-SFTP shape); there
// is no equivalent "undo a userdel" rollback, matching mariadb's own
// "commands that either succeed or fail outright" precedent, but delete now
// also retires the account's own live Match block and authorized_keys file
// through the identical validate/activate steps create/update use, so a
// reload failure after deletion still has a way back.
//
// suspend/unsuspend remain unsupported: nothing in this codebase dispatches
// them for this capability yet (Account/WebDomain suspension does not
// currently cascade into system.account-identity.v1 at all), and deciding
// what "suspended" should even mean for SFTP access (deny login outright?
// something narrower?) is a real design question of its own, deliberately
// not answered by this phase.
package identity

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

// IdentityCapability implements protocol.Capability for
// system.account-identity.v1.
type IdentityCapability struct {
	cfg      Config
	store    *generation.Store
	receipts *idempotency.Store
}

// New returns an IdentityCapability using cfg. cfg.StateRoot/accounts is
// where generation.Store nests per-resource history, mirroring nginx's own
// StateRoot/domains convention.
func New(cfg Config) *IdentityCapability {
	return &IdentityCapability{
		cfg:      cfg,
		store:    generation.New(cfg.StateRoot + "/accounts"),
		receipts: idempotency.New(),
	}
}

// Apply implements protocol.Capability.
func (c *IdentityCapability) Apply(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
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
	case protocol.OperationUpdate:
		result, err = c.applyGeneration(ctx, op, true)
	case protocol.OperationDelete:
		result, err = c.applyDelete(ctx, op)
	case protocol.OperationObserve:
		result, err = c.observe(ctx, op)
	default:
		result, err = c.rejected(op, "unsupported_operation",
			fmt.Sprintf("operation %q is not supported; system.account-identity.v1 does not implement suspend/unsuspend", op.Operation), "")
	}

	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if op.Operation != protocol.OperationObserve {
		c.receipts.Record(op.IdempotencyKey, result)
	}

	return result, nil
}

// applyGeneration is the shared create/update pipeline. requirePrior mirrors
// nginx's own flag exactly: create (false) must be the first generation for
// this resource; update (true) must not be. create additionally execs
// useradd (idempotent: an already-existing username is fine, never re-run)
// and ensures the real chroot tree exists before rendering; update does
// neither, since both are already guaranteed by this resource's own prior
// create.
func (c *IdentityCapability) applyGeneration(ctx context.Context, op protocol.OperationEnvelope, requirePrior bool) (protocol.ResultEnvelope, error) {
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

	if !requirePrior {
		exists, err := userExists(ctx, c.cfg, payload.Username)
		if err != nil {
			return c.failed(op, "id_check_failed", err.Error())
		}

		if !exists {
			if err := createSystemUser(ctx, c.cfg, payload.Username); err != nil {
				return c.failed(op, "useradd_failed", err.Error())
			}
		}

		if err := ensureChrootTree(c.cfg, payload.Username); err != nil {
			return c.failed(op, "chroot_setup_failed", err.Error())
		}
	}

	n, err := c.store.NextGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	content, err := renderMatchBlock(matchData{
		Username:   payload.Username,
		ChrootRoot: chrootRoot(c.cfg, payload.Username),
	})
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	stagingPath, err := c.writeStaging(op.ResourceID, content)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if verr := c.validateCandidate(ctx, op.ResourceID, stagingPath); verr != nil {
		c.discardStaging(op.ResourceID)

		return c.rejectedFromValidationError(op, verr)
	}

	if err := c.activateLive(op.ResourceID); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.writeAuthorizedKeys(payload.Username, renderAuthorizedKeys(payload.SshPublicKey)); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	digest, err := generation.ComputeDigest(c.cfg.SftpConfigDir)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.store.Activate(op.ResourceID, n, content, false, digest, op.DesiredStateVersion); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.writeGenerationMeta(op.ResourceID, n, payload); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if reloadErr := c.reload(ctx); reloadErr != nil {
		return c.recoverFromFailure(ctx, op, requirePrior, "reload_failed", reloadErr.Error())
	}

	if healthErr := c.waitHealthy(ctx); healthErr != nil {
		return c.recoverFromFailure(ctx, op, requirePrior, "health_check_failed", healthErr.Error())
	}

	return c.buildResult(op, protocol.StatusApplied, op.DesiredStateVersion, strconv.Itoa(n), nil)
}

// applyDelete execs userdel (idempotent: an already-absent username is fine)
// then retires this account's own live Match block and authorized_keys file
// through the same validate/activate pipeline create/update use, so a
// reload failure after deletion still has a way back via
// recoverFromFailure. The chroot tree itself (Config.AccountsRoot/username)
// is deliberately left untouched: it may hold real tenant files, and this
// phase makes no decision about their fate on account deletion -- see this
// package's own doc comment.
func (c *IdentityCapability) applyDelete(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
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

	if verr := c.validateCandidate(ctx, op.ResourceID, ""); verr != nil {
		return c.rejectedFromValidationError(op, verr)
	}

	if err := c.removeLive(op.ResourceID); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.writeAuthorizedKeys(payload.Username, []byte{}); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	exists, err := userExists(ctx, c.cfg, payload.Username)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if exists {
		if err := deleteSystemUser(ctx, c.cfg, payload.Username); err != nil {
			return c.failed(op, "userdel_failed", err.Error())
		}
	}

	digest, err := generation.ComputeDigest(c.cfg.SftpConfigDir)
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

	if healthErr := c.waitHealthy(ctx); healthErr != nil {
		return c.recoverFromFailure(ctx, op, true, "health_check_failed", healthErr.Error())
	}

	return c.buildResult(op, protocol.StatusApplied, op.DesiredStateVersion, strconv.Itoa(n), nil)
}

// observe is read-only: it recomputes the whole-SftpConfigDir digest and
// compares it to the recorded generation's manifest. It never renders,
// validates, activates, reloads, or touches useradd/userdel.
func (c *IdentityCapability) observe(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
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

	liveDigest, err := generation.ComputeDigest(c.cfg.SftpConfigDir)
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

// recoverFromFailure mirrors nginx's own failure-semantics table exactly:
// re-stage the previous generation's own stored Match-block content through
// the identical validate/activate pipeline (re-validating it, never blindly
// trusting it), re-render its own authorized_keys content from its own
// stored payload, reload, re-check health. Degraded if that rollback itself
// comes up healthy; failed only if even the rollback's own health check (or
// validation, or reload) fails. create (requirePrior == false) has no prior
// generation to fall back to, so the result is always failed, never
// degraded.
func (c *IdentityCapability) recoverFromFailure(ctx context.Context, op protocol.OperationEnvelope, requirePrior bool, code, message string) (protocol.ResultEnvelope, error) {
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

	var candidatePath string
	if !prevDeleted {
		candidatePath, err = c.writeStaging(op.ResourceID, prevContent)
		if err != nil {
			return protocol.ResultEnvelope{}, err
		}
	}

	if verr := c.validateCandidate(ctx, op.ResourceID, candidatePath); verr != nil {
		if candidatePath != "" {
			c.discardStaging(op.ResourceID)
		}

		return failed(verr.Error())
	}

	var prevPayload Payload
	if !prevDeleted {
		prevPayload, err = c.readGenerationMeta(op.ResourceID, prevN)
		if err != nil {
			return protocol.ResultEnvelope{}, err
		}
	}

	if prevDeleted {
		if err := c.removeLive(op.ResourceID); err != nil {
			return protocol.ResultEnvelope{}, err
		}
	} else if err := c.activateLive(op.ResourceID); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	authKeysContent := []byte{}
	if !prevDeleted {
		authKeysContent = renderAuthorizedKeys(prevPayload.SshPublicKey)
	}

	if err := c.writeAuthorizedKeys(currentPayloadUsername(op, prevPayload, prevDeleted), authKeysContent); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	digest, err := generation.ComputeDigest(c.cfg.SftpConfigDir)
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

	if reloadErr := c.reload(ctx); reloadErr != nil {
		return failed("reload: " + reloadErr.Error())
	}

	if healthErr := c.waitHealthy(ctx); healthErr != nil {
		return failed("health check: " + healthErr.Error())
	}

	return c.buildResult(op, protocol.StatusDegraded, op.DesiredStateVersion, strconv.Itoa(n),
		[]protocol.ResultError{{Code: code, Message: message}})
}

// currentPayloadUsername resolves which username's own authorized_keys file
// a rollback must re-render: prevPayload's own username when a prior
// generation's content is being restored, or (a delete being rolled back,
// prevDeleted meaning the *older* state was itself absent, which cannot
// happen for identity today since create is always generation 1 and has no
// prior to roll back to -- see applyGeneration's own requirePrior guard)
// falls back to the current operation's own payload username, which is
// always well-formed by this point since ParsePayload already succeeded
// earlier in the calling operation.
func currentPayloadUsername(op protocol.OperationEnvelope, prevPayload Payload, prevDeleted bool) string {
	if !prevDeleted {
		return prevPayload.Username
	}

	payload, err := ParsePayload(op.Payload)
	if err != nil {
		return ""
	}

	return payload.Username
}

func (c *IdentityCapability) failed(op protocol.OperationEnvelope, code, message string) (protocol.ResultEnvelope, error) {
	return c.buildResult(op, protocol.StatusFailed, op.DesiredStateVersion, c.currentGenerationIDOrNone(op.ResourceID),
		[]protocol.ResultError{{Code: code, Message: message}})
}

func (c *IdentityCapability) rejectedFromValidationError(op protocol.OperationEnvelope, err error) (protocol.ResultEnvelope, error) {
	var ve *ValidationError
	if errors.As(err, &ve) {
		return c.rejected(op, ve.Code, ve.Message, ve.Field)
	}

	return protocol.ResultEnvelope{}, err
}

func (c *IdentityCapability) rejected(op protocol.OperationEnvelope, code, message, field string) (protocol.ResultEnvelope, error) {
	var fieldPtr *string
	if field != "" {
		fieldPtr = &field
	}

	return c.buildResult(op, protocol.StatusRejected, c.currentObservedVersionOrZero(op.ResourceID), c.currentGenerationIDOrNone(op.ResourceID),
		[]protocol.ResultError{{Code: code, Message: message, Field: fieldPtr}})
}

// buildResult always recomputes the current whole-SftpConfigDir digest
// fresh: even a rejected or failed result must carry a schema-valid
// observed_state_digest, and the current live state is always well-defined
// regardless of this operation's outcome.
func (c *IdentityCapability) buildResult(op protocol.OperationEnvelope, status protocol.Status, observedVersion int, generationID string, errs []protocol.ResultError) (protocol.ResultEnvelope, error) {
	digest, err := generation.ComputeDigest(c.cfg.SftpConfigDir)
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
// string, or "none" if this node has no generation history for it at all.
func (c *IdentityCapability) currentGenerationIDOrNone(resourceID string) string {
	n, ok, err := c.store.CurrentGeneration(resourceID)
	if err != nil || !ok {
		return "none"
	}

	return strconv.Itoa(n)
}

func (c *IdentityCapability) currentObservedVersionOrZero(resourceID string) int {
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
