// Package protocol defines the wire types shared by every LESta node capability:
// the OperationEnvelope Laravel sends in, the ResultEnvelope the agent sends back,
// and the Capability interface every capability (fake or real) implements.
//
// Struct json tags are asserted, by TestSchemaConformance in this package, to match
// the property sets declared in docs/protocol/operation-envelope.schema.json and
// docs/protocol/result-envelope.schema.json exactly. That test is the tripwire
// against drift between this file and the schema files; keep them in lockstep.
package protocol

import (
	"encoding/json"
	"time"
)

// Operation is the verb an OperationEnvelope requests. Values match
// docs/protocol/operation-envelope.schema.json's operation.enum exactly.
type Operation string

const (
	OperationCreate    Operation = "create"
	OperationUpdate    Operation = "update"
	OperationSuspend   Operation = "suspend"
	OperationUnsuspend Operation = "unsuspend"
	OperationDelete    Operation = "delete"
	OperationObserve   Operation = "observe"
)

// Status is the outcome reported on a ResultEnvelope. Values match
// docs/protocol/result-envelope.schema.json's status.enum exactly.
type Status string

const (
	StatusApplied        Status = "applied"
	StatusAlreadyApplied Status = "already_applied"
	StatusRejected       Status = "rejected"
	StatusFailed         Status = "failed"
	StatusDegraded       Status = "degraded"
)

// OperationEnvelope is the request Laravel issues to a node capability. Decode it
// with json.NewDecoder(r).DisallowUnknownFields(), never plain json.Unmarshal: an
// unexpected key is a hard decode error, not silently ignored.
type OperationEnvelope struct {
	ProtocolVersion     string          `json:"protocol_version"`
	Capability          string          `json:"capability"`
	Operation           Operation       `json:"operation"`
	ResourceID          string          `json:"resource_id"`
	DesiredStateVersion int             `json:"desired_state_version"`
	IdempotencyKey      string          `json:"idempotency_key"`
	CorrelationID       string          `json:"correlation_id"`
	Deadline            time.Time       `json:"deadline"`
	IssuedAt            time.Time       `json:"issued_at"`
	RequestDigest       string          `json:"request_digest"`
	Payload             json.RawMessage `json:"payload"`
}

// ResultError is one entry in a ResultEnvelope's Errors array. Field is a pointer so
// that omitting it (nil) leaves the key out of the encoded JSON entirely, matching
// the schema's declaration of "field" as optional; when present it must encode as a
// JSON string or null, never be dropped silently.
type ResultError struct {
	Code    string  `json:"code"`
	Message string  `json:"message"`
	Field   *string `json:"field,omitempty"`
}

// ResultEnvelope is the response a Capability returns for an OperationEnvelope.
// Errors must always be initialized to []ResultError{}, never left nil: Go encodes
// a nil slice as JSON null, which the schema's required array field rejects.
type ResultEnvelope struct {
	ProtocolVersion      string        `json:"protocol_version"`
	Capability           string        `json:"capability"`
	ResourceID           string        `json:"resource_id"`
	IdempotencyKey       string        `json:"idempotency_key"`
	CorrelationID        string        `json:"correlation_id"`
	Status               Status        `json:"status"`
	ObservedStateVersion int           `json:"observed_state_version"`
	ObservedStateDigest  string        `json:"observed_state_digest"`
	GenerationID         string        `json:"generation_id"`
	Errors               []ResultError `json:"errors"`
	CompletedAt          time.Time     `json:"completed_at"`
}
