package mariadb

import (
	"encoding/json"
	"fmt"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// ValidatePayload runs every check the tenant database capability makes on an operation's payload
// before it touches the system, and nothing that depends on the node's own state or configuration:
// an update's check that a new extra user carries a password needs the users already provisioned,
// so it stays in Apply. The operation signer calls it before signing; Apply makes the same checks.
func ValidatePayload(operation protocol.Operation, resourceID string, payload json.RawMessage) error {
	switch operation {
	case protocol.OperationCreate, protocol.OperationUpdate, protocol.OperationSuspend,
		protocol.OperationUnsuspend, protocol.OperationDelete, protocol.OperationObserve:
	default:
		return &ValidationError{Code: "unsupported_operation", Message: fmt.Sprintf("operation %q is not supported", operation)}
	}

	p, err := ParsePayload(payload)
	if err != nil {
		return err
	}

	// Each check returns a *ValidationError; only a non-nil one is returned, so a nil pointer
	// never becomes a non-nil error.
	var verr *ValidationError

	switch operation {
	case protocol.OperationCreate:
		if verr = p.requirePasswords(); verr == nil {
			verr = p.requireNewUserPasswords(nil)
		}
	case protocol.OperationUpdate:
		verr = p.optionalPasswords()
	case protocol.OperationSuspend:
		if verr = p.forbidPasswords(); verr == nil && !p.Suspended {
			verr = &ValidationError{Code: "suspended_mismatch", Message: "payload.suspended must be true for a suspend operation", Field: "suspended"}
		}
	case protocol.OperationUnsuspend:
		if verr = p.forbidPasswords(); verr == nil && p.Suspended {
			verr = &ValidationError{Code: "suspended_mismatch", Message: "payload.suspended must be false for an unsuspend operation", Field: "suspended"}
		}
	default:
		verr = p.forbidPasswords()
	}

	if verr != nil {
		return verr
	}

	return nil
}
