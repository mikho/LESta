package servicecontrol

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/protocol"
)

const validateResourceID = "00000000-0000-4000-8000-000000000001"

func TestValidatePayloadAcceptsEveryAction(t *testing.T) {
	for _, action := range []string{"start", "stop", "restart"} {
		raw, _ := json.Marshal(map[string]string{"unit": "nginx", "action": action})
		if err := ValidatePayload(protocol.OperationUpdate, validateResourceID, raw); err != nil {
			t.Errorf("%s: %v", action, err)
		}
	}
}

func TestValidatePayloadRefusesHostilePayloadsWithApplysCode(t *testing.T) {
	long := strings.Repeat("a", 4096)

	for _, tc := range []struct {
		name      string
		operation protocol.Operation
		raw       string
		code      string
	}{
		{"create", protocol.OperationCreate, `{"unit":"nginx","action":"stop"}`, "unsupported_operation"},
		{"delete", protocol.OperationDelete, `{"unit":"nginx","action":"stop"}`, "unsupported_operation"},
		{"unknown field", protocol.OperationUpdate, `{"unit":"nginx","action":"stop","extra":1}`, "invalid_payload"},
		{"not json", protocol.OperationUpdate, `nope`, "invalid_payload"},
		{"unknown action", protocol.OperationUpdate, `{"unit":"nginx","action":"reload"}`, "invalid_action"},
		{"dot dot", protocol.OperationUpdate, `{"unit":"../nginx","action":"stop"}`, "unit_not_allowed"},
		{"slash", protocol.OperationUpdate, `{"unit":"a/b","action":"stop"}`, "unit_not_allowed"},
		{"nul", protocol.OperationUpdate, `{"unit":"nginx\u0000","action":"stop"}`, "unit_not_allowed"},
		{"newline", protocol.OperationUpdate, `{"unit":"nginx\n","action":"stop"}`, "unit_not_allowed"},
		{"long", protocol.OperationUpdate, `{"unit":"` + long + `","action":"stop"}`, "unit_not_allowed"},
	} {
		err := ValidatePayload(tc.operation, validateResourceID, json.RawMessage(tc.raw))

		var verr *ValidationError
		if !errors.As(err, &verr) || verr.Code != tc.code {
			t.Errorf("%s: got %v, want code %s", tc.name, err, tc.code)
		}
	}
}
