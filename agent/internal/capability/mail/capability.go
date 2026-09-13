// Package mail implements the mail.smtp-imap.v1 capability: real Exim +
// Dovecot virtual mail hosting, provisioned by execing the real exim,
// doveadm, sievec, and openssl binaries against the tenant node -- mirroring
// this module's own "exec a real binary, no service-specific Go driver
// dependency" zero-dependency policy exactly.
//
// See config.go's own doc comment for why this capability maintains
// aggregate lookup-data files (domains.list, accounts.list, a Dovecot
// passwd-file, ...) rather than bind9's per-resource glob-included fragment
// model: Exim has no native glob-file-include mechanism, so the router/
// transport/ACL/authenticator STRUCTURE is a fixed, pre-existing,
// operator/installer-provided prerequisite (exactly like nginx.conf's own
// required include line), and this capability only ever rewrites the DATA
// those structural rules look up, in full, on every apply.
//
// The domain (MailDomain, Laravel-side) is the single provisioning
// resource, mirroring DnsZone's own zone-embeds-records precedent: every
// account mutation is dispatched as an Update against its owning domain,
// never as its own resource, since a real Exim virtual-domain config
// renders one artifact per domain, not per account (see payload.go).
//
// Scope of this pass, deliberately: real Exim relay-safety enforcement (no
// relay for a non-hosted domain, no delivery to an unknown local part), real
// SMTP AUTH via Dovecot-hashed credentials, real DKIM signing with a
// node-generated, node-only private key, real per-account Sieve
// (autoreply/forwarding), and real antivirus/antispam ACL gating. A
// successful create/update/suspend/unsuspend apply also reports the active
// DKIM public key (never the private key) on ResultEnvelope.Data, for
// Laravel to publish as a DNS TXT record via the dns.bind9.v1 capability
// (see dkim.go's own doc comment and PublishesDkimDnsRecord.php). NOT in
// this pass: real spamd/clamd daemon installation (the ACLs this pass
// renders reference their fixed, well-known local sockets structurally;
// provisioning the daemons themselves is installer work, out of scope for a
// Go-capability-only phase, mirroring every other capability's own
// installer-comes-later precedent).
package mail

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

// MailCapability implements protocol.Capability for mail.smtp-imap.v1.
type MailCapability struct {
	cfg      Config
	store    *generation.Store
	receipts *idempotency.Store
}

// New returns a MailCapability rooted at cfg's paths.
func New(cfg Config) *MailCapability {
	return &MailCapability{
		cfg:      cfg,
		store:    generation.New(cfg.StateRoot + "/domains"),
		receipts: idempotency.New(),
	}
}

// Apply implements protocol.Capability.
func (c *MailCapability) Apply(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
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
		result, err = c.applyDomain(ctx, op, false)
	case protocol.OperationUpdate, protocol.OperationSuspend, protocol.OperationUnsuspend:
		result, err = c.applyDomain(ctx, op, true)
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

// applyDomain is the one internal pipeline create/update/suspend/unsuspend
// all funnel through: they differ only in requirePrior (create requires no
// prior generation; the other three require one) and in whether
// payload.Suspended is set, a pure function of the payload, mirroring
// bind9's own applyGeneration precedent exactly.
func (c *MailCapability) applyDomain(ctx context.Context, op protocol.OperationEnvelope, requirePrior bool) (protocol.ResultEnvelope, error) {
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

	dkimActive := payload.DkimEnabled && !payload.Suspended

	if err := c.ensureDKIMKey(payload.Domain, payload.DkimActiveSelector, dkimActive); err != nil {
		return protocol.ResultEnvelope{}, fmt.Errorf("ensuring active DKIM key for %s: %w", payload.Domain, err)
	}

	if payload.DkimPendingSelector != nil {
		if err := c.ensureDKIMKey(payload.Domain, *payload.DkimPendingSelector, dkimActive); err != nil {
			return protocol.ResultEnvelope{}, fmt.Errorf("ensuring pending DKIM key for %s: %w", payload.Domain, err)
		}
	}

	if payload.DkimRetireSelector != nil {
		if err := c.retireDKIMKey(payload.Domain, *payload.DkimRetireSelector); err != nil {
			return protocol.ResultEnvelope{}, fmt.Errorf("retiring DKIM key for %s: %w", payload.Domain, err)
		}
	}

	n, err := c.store.NextGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if applyErr := c.applyAndActivate(ctx, op.ResourceID, payload); applyErr != nil {
		return c.recoverFromFailure(ctx, op, requirePrior, "apply_failed", applyErr.Error())
	}

	digest, err := generation.ComputeDigest(c.cfg.EximDataDir)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	metaBytes, err := payload.marshalMeta()
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.store.Activate(op.ResourceID, n, metaBytes, false, digest, op.DesiredStateVersion); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if err := c.writeGenerationMeta(op.ResourceID, n, payload); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	result, err := c.buildResult(op, protocol.StatusApplied, op.DesiredStateVersion, strconv.Itoa(n), nil)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	dkimData, err := c.dkimResultData(payload.Domain, payload.DkimActiveSelector, payload.DkimPendingSelector, dkimActive)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}
	result.Data = dkimData

	return result, nil
}

// applyAndActivate renders, validates, and activates domains' full aggregate
// state (Exim data files, Dovecot passwd file, per-account Sieve scripts),
// then reloads both services and health-checks the result. It never touches
// generation.Store itself (the caller does that only once this whole
// pipeline has already succeeded): a partial failure here must leave the
// PREVIOUSLY-activated generation as the only one ever recorded current.
func (c *MailCapability) applyAndActivate(ctx context.Context, resourceID string, payload Payload) error {
	domains, err := c.activeDomains(resourceID, payload)
	if err != nil {
		return err
	}

	credentials, err := c.loadCredentials()
	if err != nil {
		return err
	}

	data, err := c.render(ctx, domains, credentials)
	if err != nil {
		return err
	}

	// Persisted before activation, deliberately: if this process crashed
	// between activating the live passwd file and archiving the same hash,
	// the NEXT apply's own render would read the STALE pre-crash hash back
	// out of the archive and write it into the freshly-rendered passwd
	// file, silently reverting an already-live password change. Saving
	// first means the only inconsistency a crash can leave behind is the
	// live file lagging one step behind an archive that already has the
	// right answer, which self-heals the moment any later apply re-renders
	// from it.
	if err := c.saveCredentials(credentials); err != nil {
		return err
	}

	sieveStaged, err := c.writeSieveScripts(ctx, domains)
	if err != nil {
		return err
	}

	files := c.aggregateFiles(data)

	if err := c.writeStaging(files); err != nil {
		discardSieveScripts(sieveStaged)
		c.discardStaging(files)

		return err
	}

	if err := c.activateLive(files); err != nil {
		discardSieveScripts(sieveStaged)

		return err
	}

	if err := activateSieveScripts(sieveStaged); err != nil {
		return err
	}

	if err := c.validateEximConfig(ctx); err != nil {
		return err
	}

	if err := c.reloadExim(ctx); err != nil {
		return fmt.Errorf("reloading exim: %w", err)
	}

	if err := c.reloadDovecot(ctx); err != nil {
		return fmt.Errorf("reloading dovecot: %w", err)
	}

	return c.healthCheck(ctx, payload)
}

// healthCheck proves, via real live protocol round trips, that this apply's
// own intent actually took effect: a suspended domain's own probe address
// must now be rejected; an active domain's own first active account (if
// any) must be accepted. A domain with no accounts at all yet (freshly
// created) only gets the reject-side probe, since there is no real address
// to expect acceptance for.
func (c *MailCapability) healthCheck(ctx context.Context, payload Payload) error {
	probeLocalPart := "lesta-healthcheck-probe"

	if payload.Suspended {
		return c.waitSMTPRejects(ctx, probeLocalPart+"@"+payload.Domain)
	}

	if err := c.waitSMTPRejects(ctx, probeLocalPart+"@"+payload.Domain); err != nil {
		return err
	}

	for _, a := range payload.Accounts {
		if a.Suspended {
			continue
		}

		return c.waitSMTPAccepts(ctx, a.LocalPart+"@"+payload.Domain)
	}

	return nil
}

// applyDelete removes resourceID's domain entirely: every account beneath
// it disappears from the next render (activeDomains simply excludes it,
// since it is never in the sibling list once its own generation records a
// deletion), its DKIM key material is left in place (deliberately: a
// deleted domain could in principle be re-created later, and there is no
// safety reason to eagerly destroy key material the moment the domain
// itself is removed; an operator-triggered key-purge is a disclosed,
// separate future concern).
func (c *MailCapability) applyDelete(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	if _, verr := ParsePayload(op.Payload); verr != nil {
		return c.rejectedFromValidationError(op, verr)
	}

	hasCurrent, err := c.store.HasCurrent(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}
	if !hasCurrent {
		return c.rejected(op, "unknown_resource", "no prior generation exists for this resource on this node", "")
	}

	deletedPayload, err := c.currentPayload(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if applyErr := c.applyAndActivateAbsent(ctx, op.ResourceID, deletedPayload.Domain); applyErr != nil {
		return c.recoverFromFailure(ctx, op, true, "delete_failed", applyErr.Error())
	}

	digest, err := generation.ComputeDigest(c.cfg.EximDataDir)
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

	return c.buildResult(op, protocol.StatusApplied, op.DesiredStateVersion, strconv.Itoa(n), nil)
}

// applyAndActivateAbsent re-renders every OTHER known domain's own state
// with domain itself excluded entirely, then validates/activates/reloads/
// health-checks exactly like applyAndActivate, proving the just-removed
// domain's own probe address is now genuinely rejected.
func (c *MailCapability) applyAndActivateAbsent(ctx context.Context, excludeResourceID, domain string) error {
	domains, err := c.listKnownDomains(excludeResourceID)
	if err != nil {
		return err
	}

	credentials, err := c.loadCredentials()
	if err != nil {
		return err
	}

	data, err := c.render(ctx, domains, credentials)
	if err != nil {
		return err
	}

	if err := c.saveCredentials(credentials); err != nil {
		return err
	}

	sieveStaged, err := c.writeSieveScripts(ctx, domains)
	if err != nil {
		return err
	}

	files := c.aggregateFiles(data)

	if err := c.writeStaging(files); err != nil {
		discardSieveScripts(sieveStaged)
		c.discardStaging(files)

		return err
	}

	if err := c.activateLive(files); err != nil {
		discardSieveScripts(sieveStaged)

		return err
	}

	if err := activateSieveScripts(sieveStaged); err != nil {
		return err
	}

	if err := c.validateEximConfig(ctx); err != nil {
		return err
	}

	if err := c.reloadExim(ctx); err != nil {
		return fmt.Errorf("reloading exim: %w", err)
	}

	if err := c.reloadDovecot(ctx); err != nil {
		return fmt.Errorf("reloading dovecot: %w", err)
	}

	return c.waitSMTPRejects(ctx, "lesta-healthcheck-probe@"+domain)
}

// currentPayload reads resourceID's own currently-active generation's
// meta, the same read recoverFromFailure and listKnownDomains each already
// perform for a sibling; exposed as its own helper for applyDelete's use.
func (c *MailCapability) currentPayload(resourceID string) (Payload, error) {
	n, ok, err := c.store.CurrentGeneration(resourceID)
	if err != nil {
		return Payload{}, err
	}
	if !ok {
		return Payload{}, fmt.Errorf("no current generation for %s", resourceID)
	}

	return c.readGenerationMeta(resourceID, n)
}

// observe is read-only: it recomputes the whole-EximDataDir digest and
// compares it to the recorded generation's manifest, mirroring bind9's own
// observe exactly. It never renders, validates, activates, or reloads
// anything.
func (c *MailCapability) observe(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
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

	liveDigest, err := generation.ComputeDigest(c.cfg.EximDataDir)
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
// re-render and re-activate the PREVIOUS generation's own known-good state,
// reload, re-check health. Degraded if that rollback itself comes up
// healthy (last-known-good is serving, the requested change didn't take
// effect); failed only if even the rollback's own health check (or its own
// render/validate/reload) fails. create (requirePrior == false) has no
// prior generation to fall back to, so the result is always failed, never
// degraded, mirroring bind9's own identical distinction.
func (c *MailCapability) recoverFromFailure(ctx context.Context, op protocol.OperationEnvelope, requirePrior bool, code, message string) (protocol.ResultEnvelope, error) {
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

	var prevPayload Payload

	if prevDeleted {
		if err := c.applyAndActivateAbsent(ctx, op.ResourceID, ""); err != nil {
			return failed(err.Error())
		}
	} else {
		prevPayload, err = c.readGenerationMeta(op.ResourceID, prevN)
		if err != nil {
			return protocol.ResultEnvelope{}, err
		}

		if err := c.applyAndActivate(ctx, op.ResourceID, prevPayload); err != nil {
			return failed(err.Error())
		}
	}

	digest, err := generation.ComputeDigest(c.cfg.EximDataDir)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if prevDeleted {
		if err := c.store.Activate(op.ResourceID, n, nil, true, digest, op.DesiredStateVersion); err != nil {
			return protocol.ResultEnvelope{}, err
		}
	} else {
		metaBytes, err := prevPayload.marshalMeta()
		if err != nil {
			return protocol.ResultEnvelope{}, err
		}

		if err := c.store.Activate(op.ResourceID, n, metaBytes, false, digest, op.DesiredStateVersion); err != nil {
			return protocol.ResultEnvelope{}, err
		}

		if err := c.writeGenerationMeta(op.ResourceID, n, prevPayload); err != nil {
			return protocol.ResultEnvelope{}, err
		}
	}

	return c.buildResult(op, protocol.StatusDegraded, op.DesiredStateVersion, strconv.Itoa(n),
		[]protocol.ResultError{{Code: code, Message: message}})
}

func (c *MailCapability) rejectedFromValidationError(op protocol.OperationEnvelope, err error) (protocol.ResultEnvelope, error) {
	var ve *ValidationError
	if errors.As(err, &ve) {
		return c.rejected(op, ve.Code, ve.Message, ve.Field)
	}

	return protocol.ResultEnvelope{}, err
}

func (c *MailCapability) rejected(op protocol.OperationEnvelope, code, message, field string) (protocol.ResultEnvelope, error) {
	var fieldPtr *string
	if field != "" {
		fieldPtr = &field
	}

	return c.buildResult(op, protocol.StatusRejected, c.currentObservedVersionOrZero(op.ResourceID), c.currentGenerationIDOrNone(op.ResourceID),
		[]protocol.ResultError{{Code: code, Message: message, Field: fieldPtr}})
}

// buildResult always recomputes the current whole-EximDataDir digest fresh:
// even a rejected or failed result must carry a schema-valid
// observed_state_digest, and the current live state is always well-defined
// regardless of this operation's outcome.
func (c *MailCapability) buildResult(op protocol.OperationEnvelope, status protocol.Status, observedVersion int, generationID string, errs []protocol.ResultError) (protocol.ResultEnvelope, error) {
	digest, err := generation.ComputeDigest(c.cfg.EximDataDir)
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

func (c *MailCapability) currentGenerationIDOrNone(resourceID string) string {
	n, ok, err := c.store.CurrentGeneration(resourceID)
	if err != nil || !ok {
		return "none"
	}

	return strconv.Itoa(n)
}

func (c *MailCapability) currentObservedVersionOrZero(resourceID string) int {
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
