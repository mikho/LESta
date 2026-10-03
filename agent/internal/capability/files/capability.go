package files

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"strings"
	"time"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// Capability implements protocol.Capability for files.manager.v1.
type Capability struct {
	cfg Config
}

// New returns a Capability rooted at cfg's paths.
func New(cfg Config) *Capability {
	return &Capability{cfg: cfg}
}

// Apply implements protocol.Capability. Unlike every config-rendering
// capability, there is no generation/validate/activate/reload pipeline
// here, and no persistent state of its own beyond the real filesystem
// already owned by identity/nginx/php-fpm -- a file operation either
// applies against the real docroot right now, or is rejected.
func (c *Capability) Apply(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParsePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(op, verr)
	}

	req := applyRequest{
		AccountUsername: payload.AccountUsername,
		ResourceID:      op.ResourceID,
		Path:            payload.Path,
		NewPath:         payload.NewPath,
		ContentBase64:   payload.ContentBase64,
		IsDirectory:     payload.IsDirectory,
		Recursive:       payload.Recursive,
	}

	switch op.Operation {
	case protocol.OperationObserve:
		req.Verb = "observe"
	case protocol.OperationCreate:
		req.Verb = "create"
	case protocol.OperationUpdate:
		if payload.NewPath == "" && payload.ContentBase64 == "" {
			return c.rejected(op, "invalid_update", "update requires either new_path (rename) or content_base64 (overwrite)")
		}

		req.Verb = "update"
	case protocol.OperationDelete:
		req.Verb = "delete"
	default:
		return c.rejected(op, "unsupported_operation", fmt.Sprintf("operation %q is not supported", op.Operation))
	}

	resp, err := applyPrivileged(ctx, c.cfg, req)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if !resp.OK {
		return c.rejected(op, resp.Code, resp.Message)
	}

	return c.applied(op, resp.Data), nil
}

func zeroDigest() string {
	return "sha256:" + strings.Repeat("0", 64)
}

// applied builds a successful ResultEnvelope. GenerationID "none" and a
// zero digest, mirroring metrics.usage.v1's own identical choice: this
// capability has no generation history of its own to report either.
func (c *Capability) applied(op protocol.OperationEnvelope, data json.RawMessage) protocol.ResultEnvelope {
	return protocol.ResultEnvelope{
		ProtocolVersion:      op.ProtocolVersion,
		Capability:           op.Capability,
		ResourceID:           op.ResourceID,
		IdempotencyKey:       op.IdempotencyKey,
		CorrelationID:        op.CorrelationID,
		Status:               protocol.StatusApplied,
		ObservedStateVersion: op.DesiredStateVersion,
		ObservedStateDigest:  zeroDigest(),
		GenerationID:         "none",
		Errors:               []protocol.ResultError{},
		CompletedAt:          time.Now().UTC(),
		Data:                 data,
	}
}

func (c *Capability) rejected(op protocol.OperationEnvelope, code, message string) (protocol.ResultEnvelope, error) {
	return protocol.ResultEnvelope{
		ProtocolVersion:      op.ProtocolVersion,
		Capability:           op.Capability,
		ResourceID:           op.ResourceID,
		IdempotencyKey:       op.IdempotencyKey,
		CorrelationID:        op.CorrelationID,
		Status:               protocol.StatusRejected,
		ObservedStateVersion: 0,
		ObservedStateDigest:  zeroDigest(),
		GenerationID:         "none",
		Errors:               []protocol.ResultError{{Code: code, Message: message}},
		CompletedAt:          time.Now().UTC(),
	}, nil
}

func (c *Capability) rejectedFromValidationError(op protocol.OperationEnvelope, verr error) (protocol.ResultEnvelope, error) {
	var ve *ValidationError
	if !errors.As(verr, &ve) {
		return protocol.ResultEnvelope{}, verr
	}

	field := ve.Field

	return protocol.ResultEnvelope{
		ProtocolVersion:      op.ProtocolVersion,
		Capability:           op.Capability,
		ResourceID:           op.ResourceID,
		IdempotencyKey:       op.IdempotencyKey,
		CorrelationID:        op.CorrelationID,
		Status:               protocol.StatusRejected,
		ObservedStateVersion: 0,
		ObservedStateDigest:  zeroDigest(),
		GenerationID:         "none",
		Errors:               []protocol.ResultError{{Code: ve.Code, Message: ve.Message, Field: &field}},
		CompletedAt:          time.Now().UTC(),
	}, nil
}
