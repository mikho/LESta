package fragment

import (
	"errors"
	"fmt"
	"strconv"
	"time"

	"github.com/mikho/LESta/agent/internal/generation"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// ValidationError is a rejection of the request itself (a payload field, or the service's own
// checker refusing the candidate), as opposed to a failure to apply it.
type ValidationError struct {
	Code    string
	Message string
	Field   string
}

func (e *ValidationError) Error() string {
	return fmt.Sprintf("%s: %s (field=%s)", e.Code, e.Message, e.Field)
}

// Results shapes one capability's result envelopes. Every result, even a rejected or failed one,
// carries a schema-valid observed_state_digest recomputed fresh from the live directory: the
// current live state is always well-defined regardless of this operation's outcome.
type Results struct {
	Store *generation.Store

	// LiveDir is digested for every result, unless Digest is set.
	LiveDir string

	// Digest, when set, computes the observed digest for a resource instead (php-fpm: the pool
	// directory of the resource's own version).
	Digest func(resourceID string) (string, error)
}

// Build assembles the envelope for op.
func (r Results) Build(op protocol.OperationEnvelope, status protocol.Status, observedVersion int, generationID string, errs []protocol.ResultError) (protocol.ResultEnvelope, error) {
	var (
		digest string
		err    error
	)

	if r.Digest != nil {
		digest, err = r.Digest(op.ResourceID)
	} else {
		digest, err = generation.ComputeDigest(r.LiveDir)
	}

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

// Rejected is a rejected result carrying one error, with the resource's current generation and
// version as observed.
func (r Results) Rejected(op protocol.OperationEnvelope, code, message, field string) (protocol.ResultEnvelope, error) {
	var fieldPtr *string
	if field != "" {
		fieldPtr = &field
	}

	return r.Build(op, protocol.StatusRejected, r.CurrentObservedVersionOrZero(op.ResourceID), r.CurrentGenerationIDOrNone(op.ResourceID),
		[]protocol.ResultError{{Code: code, Message: message, Field: fieldPtr}})
}

// RejectedFromValidationError turns a *ValidationError into a rejected result; any other error is
// returned as is, an infrastructure failure rather than a verdict.
func (r Results) RejectedFromValidationError(op protocol.OperationEnvelope, err error) (protocol.ResultEnvelope, error) {
	var ve *ValidationError
	if errors.As(err, &ve) {
		return r.Rejected(op, ve.Code, ve.Message, ve.Field)
	}

	return protocol.ResultEnvelope{}, err
}

// CurrentGenerationIDOrNone reports resourceID's current generation as a string, or "none" if
// this node has no generation history for it at all (e.g. a rejection that never got far enough
// to establish one).
func (r Results) CurrentGenerationIDOrNone(resourceID string) string {
	n, ok, err := r.Store.CurrentGeneration(resourceID)
	if err != nil || !ok {
		return "none"
	}

	return strconv.Itoa(n)
}

// CurrentObservedVersionOrZero reports the desired_state_version the current generation was
// applied for, or 0 without one.
func (r Results) CurrentObservedVersionOrZero(resourceID string) int {
	n, ok, err := r.Store.CurrentGeneration(resourceID)
	if err != nil || !ok {
		return 0
	}

	manifest, err := r.Store.ReadManifest(resourceID, n)
	if err != nil {
		return 0
	}

	return manifest.DesiredStateVersion
}
