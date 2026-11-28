package metrics

import (
	"encoding/json"
	"fmt"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// ValidatePayload runs every check the metrics capability makes on an operation's payload before it
// touches the system, and nothing that depends on the node's own state or configuration. The
// operation signer calls it before signing; Apply makes the same checks.
func ValidatePayload(operation protocol.Operation, resourceID string, payload json.RawMessage) error {
	if operation != protocol.OperationObserve {
		return &ValidationError{Code: "unsupported_operation", Message: fmt.Sprintf("operation %q is not supported; metrics.usage.v1 only implements observe", operation)}
	}

	_, err := ParsePayload(payload)

	return err
}
