package acme

import (
	"encoding/json"
	"fmt"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// ValidatePayload runs every check the ACME capability makes on an operation's payload before it
// touches the system, and nothing that depends on the node's own state or configuration. The
// operation signer calls it before signing; Apply makes the same checks.
func ValidatePayload(operation protocol.Operation, resourceID string, payload json.RawMessage) error {
	switch operation {
	case protocol.OperationCreate, protocol.OperationUpdate:
		_, err := ParsePayload(payload)

		return err
	case protocol.OperationDelete:
		p, err := ParsePayload(payload)
		if err != nil {
			return err
		}

		if p.Kind != KindHTTP01Challenge {
			return &ValidationError{Code: "unsupported_operation", Message: fmt.Sprintf("delete is not supported for kind %q", p.Kind)}
		}

		return nil
	case protocol.OperationObserve:
		// observe reads only the recorded generation, never the payload.
		return nil
	default:
		return &ValidationError{Code: "unsupported_operation", Message: fmt.Sprintf("operation %q is not supported", operation)}
	}
}
