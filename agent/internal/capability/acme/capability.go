// Package acme implements the tls.acme.v1 capability: the node-local half of
// ACME certificate issuance. Unlike web.nginx.v1/web.apache.v1/dns.bind9.v1,
// this capability never renders a template, never invokes an external
// validator or reload binary, and never runs a health check -- it only ever
// atomically writes (or, for HTTP-01 challenges, removes) plain files under
// its own owned root. The real ACME v2 protocol client (directory discovery,
// account registration, order/authorization/challenge/finalize, JWS signing)
// lives entirely in Laravel (App\Jobs\IssueAcmeCertificate), which is the
// only place with the DB access and unbounded wall-clock time a multi-minute
// challenge/validation round trip needs; the strictly one-shot node protocol
// (one OperationEnvelope in, one ResultEnvelope out, then this process exits)
// cannot itself wait on a Certificate Authority. See payload.go for the two
// resource kinds this capability manages, activate.go for the atomic
// write-then-rename helper, digest.go for this capability's own recursive
// owned-root fingerprint (nginx/apache/bind9's own generation.ComputeDigest
// only ever globs a flat directory of *.conf files, which doesn't fit this
// capability's nested, extension-varied owned root), and this file for
// wiring the six protocol operations through one shared internal pipeline.
package acme

import (
	"context"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"time"

	"github.com/mikho/LESta/agent/internal/generation"
	"github.com/mikho/LESta/agent/internal/idempotency"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// AcmeCapability implements protocol.Capability for tls.acme.v1.
type AcmeCapability struct {
	cfg      Config
	store    *generation.Store
	receipts *idempotency.Store
}

// New returns an AcmeCapability rooted at cfg.StateRoot. Generation history
// (a monotonic per-resource-id operation log used only for idempotency
// bookkeeping and observe's own drift detection -- never a rollback target;
// see Apply's own doc comment on why this capability has no rollback path at
// all) nests under cfg.StateRoot/domains, mirroring nginx's and apache's own
// StateRoot/domains convention.
func New(cfg Config) *AcmeCapability {
	return &AcmeCapability{
		cfg:      cfg,
		store:    generation.New(filepath.Join(cfg.StateRoot, "domains")),
		receipts: idempotency.New(),
	}
}

// Apply implements protocol.Capability. There is no rollback/recovery path
// here (contrast nginx/apache/bind9's own recoverFromFailure): those
// capabilities need one because a reload or health check can fail *after*
// content has already been staged and activated, leaving live state to
// restore. This capability never reloads anything and never health-checks
// anything -- a file write either succeeds (StatusApplied) or fails outright
// (a plain Go error, the "no verdict reached" bucket: an unwritable
// StateRoot, a full disk), never something that needs a last-known-good
// state restored around it.
func (c *AcmeCapability) Apply(_ context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
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
	case protocol.OperationCreate, protocol.OperationUpdate:
		result, err = c.applyWrite(op)
	case protocol.OperationDelete:
		result, err = c.applyDelete(op)
	case protocol.OperationObserve:
		result, err = c.observe(op)
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

// applyWrite handles create/update for both resource kinds: create has no
// meaningful "must not already exist" invariant here (unlike a vhost, an
// ACME challenge or certificate has no lifecycle hazard in being written
// twice -- a renewal legitimately re-writes the same certificate path many
// times over a domain's life), so, unlike nginx/apache/bind9, create and
// update share one identical code path with no requirePrior distinction.
func (c *AcmeCapability) applyWrite(op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParsePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(op, verr)
	}

	switch payload.Kind {
	case KindHTTP01Challenge:
		if err := writeFileAtomic(c.challengeFilePath(payload.Token), []byte(payload.KeyAuthorization), 0o644); err != nil {
			return protocol.ResultEnvelope{}, err
		}
	case KindCertificate:
		if err := writeFileAtomic(c.fullChainPath(payload.Domain), []byte(payload.FullChainPEM), 0o644); err != nil {
			return protocol.ResultEnvelope{}, err
		}

		// The private key gets the stricter mode: never world- or
		// group-readable, mirroring the ADR's own refused_roots treatment
		// of /etc/ssl/private for exactly this kind of material.
		if err := writeFileAtomic(c.privateKeyPath(payload.Domain), []byte(payload.PrivateKeyPEM), 0o600); err != nil {
			return protocol.ResultEnvelope{}, err
		}
	}

	return c.recordGenerationAndBuildResult(op, payload, false)
}

// applyDelete only ever supports KindHTTP01Challenge: a certificate has no
// delete operation this phase (revocation is out of scope), so a delete
// against kind=certificate is rejected outright rather than silently
// no-op'd.
func (c *AcmeCapability) applyDelete(op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParsePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(op, verr)
	}

	if payload.Kind != KindHTTP01Challenge {
		return c.rejected(op, "unsupported_operation", fmt.Sprintf("delete is not supported for kind %q", payload.Kind), "")
	}

	// Removing an already-absent file is not an error: a retried delete (or
	// a delete for a challenge whose create never actually landed, e.g.
	// because an earlier step in the same issuance attempt failed first)
	// stays idempotent.
	if err := os.Remove(c.challengeFilePath(payload.Token)); err != nil && !os.IsNotExist(err) {
		return protocol.ResultEnvelope{}, fmt.Errorf("removing challenge file for token %s: %w", payload.Token, err)
	}

	return c.recordGenerationAndBuildResult(op, payload, true)
}

// observe is read-only: it recomputes the whole-StateRoot digest and
// compares it to the recorded generation's manifest. It never writes or
// removes anything.
func (c *AcmeCapability) observe(op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
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

	liveDigest, err := computeDigest(c.cfg.StateRoot)
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

// recordGenerationAndBuildResult persists payload as this resource's next
// generation's metadata (the same sidecar-JSON role apache's/bind9's own
// writeGenerationMeta plays, here doubling as generation.Store's own
// "content" since there is no separately-rendered template output distinct
// from the payload) and builds the resulting ResultEnvelope.
func (c *AcmeCapability) recordGenerationAndBuildResult(op protocol.OperationEnvelope, payload Payload, deleted bool) (protocol.ResultEnvelope, error) {
	n, err := c.store.NextGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	digest, err := computeDigest(c.cfg.StateRoot)
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

	return c.buildResult(op, protocol.StatusApplied, op.DesiredStateVersion, strconv.Itoa(n), nil)
}

func (c *AcmeCapability) challengeFilePath(token string) string {
	return filepath.Join(c.cfg.StateRoot, "http-01", token)
}

func (c *AcmeCapability) certDir(domain string) string {
	return filepath.Join(c.cfg.StateRoot, "certs", domain)
}

func (c *AcmeCapability) fullChainPath(domain string) string {
	return filepath.Join(c.certDir(domain), "fullchain.pem")
}

func (c *AcmeCapability) privateKeyPath(domain string) string {
	return filepath.Join(c.certDir(domain), "privkey.pem")
}

func (c *AcmeCapability) rejectedFromValidationError(op protocol.OperationEnvelope, err error) (protocol.ResultEnvelope, error) {
	var ve *ValidationError
	if errors.As(err, &ve) {
		return c.rejected(op, ve.Code, ve.Message, ve.Field)
	}

	return protocol.ResultEnvelope{}, err
}

func (c *AcmeCapability) rejected(op protocol.OperationEnvelope, code, message, field string) (protocol.ResultEnvelope, error) {
	var fieldPtr *string
	if field != "" {
		fieldPtr = &field
	}

	return c.buildResult(op, protocol.StatusRejected, c.currentObservedVersionOrZero(op.ResourceID), c.currentGenerationIDOrNone(op.ResourceID),
		[]protocol.ResultError{{Code: code, Message: message, Field: fieldPtr}})
}

// buildResult always recomputes the current whole-StateRoot digest fresh:
// even a rejected result must carry a schema-valid observed_state_digest,
// and the current live state is always well-defined regardless of this
// operation's outcome.
func (c *AcmeCapability) buildResult(op protocol.OperationEnvelope, status protocol.Status, observedVersion int, generationID string, errs []protocol.ResultError) (protocol.ResultEnvelope, error) {
	digest, err := computeDigest(c.cfg.StateRoot)
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
// string, or "none" if this node has no generation history for it at all
// (e.g. a rejection that never got far enough to establish one).
func (c *AcmeCapability) currentGenerationIDOrNone(resourceID string) string {
	n, ok, err := c.store.CurrentGeneration(resourceID)
	if err != nil || !ok {
		return "none"
	}

	return strconv.Itoa(n)
}

func (c *AcmeCapability) currentObservedVersionOrZero(resourceID string) int {
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
