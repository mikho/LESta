package bind9

import (
	"encoding/json"
	"fmt"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// ValidatePayload runs every check the bind9 capability makes on an operation's payload before
// it touches the system, and nothing that depends on the node's own state or configuration. The
// operation signer calls it before signing; Apply makes the same checks. resourceID is part of the
// shared signature; this capability binds nothing in its payload to it.
func ValidatePayload(operation protocol.Operation, resourceID string, payload json.RawMessage) error {
	switch operation {
	case protocol.OperationCreate, protocol.OperationUpdate, protocol.OperationSuspend, protocol.OperationUnsuspend, protocol.OperationDelete:
		_, err := ParsePayload(payload)

		return err
	case protocol.OperationObserve:
		// observe reads the recorded generation and never looks at the payload.
		return nil
	default:
		return &ValidationError{Code: "unsupported_operation", Message: fmt.Sprintf("operation %q is not supported", operation)}
	}
}
