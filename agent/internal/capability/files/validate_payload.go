package files

import (
	"encoding/json"
	"fmt"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// ValidatePayload runs every check the file manager makes on an operation's payload before it
// touches the system, and nothing that depends on the node's own state or configuration. The
// operation signer calls it before signing; Apply makes the same checks, in the same order.
func ValidatePayload(operation protocol.Operation, resourceID string, payload json.RawMessage) error {
	p, err := ParsePayload(payload)
	if err != nil {
		return err
	}

	if p.LogKind != "" {
		if operation != protocol.OperationObserve {
			return &ValidationError{Code: "unsupported_operation", Message: "logs can only be observed"}
		}

		return nil
	}

	switch operation {
	case protocol.OperationObserve, protocol.OperationCreate, protocol.OperationDelete:
		return nil
	case protocol.OperationUpdate:
		if p.NewPath == "" && p.ContentBase64 == "" {
			return &ValidationError{Code: "invalid_update", Message: "update requires either new_path (rename) or content_base64 (overwrite)"}
		}

		return nil
	default:
		return &ValidationError{Code: "unsupported_operation", Message: fmt.Sprintf("operation %q is not supported", operation)}
	}
}
