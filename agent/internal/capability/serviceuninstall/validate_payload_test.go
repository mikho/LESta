package serviceuninstall

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/protocol"
)

const validateResourceID = "00000000-0000-4000-8000-000000000001"

func TestValidatePayloadAcceptsEveryListedService(t *testing.T) {
	for _, service := range Services {
		for _, confirm := range []bool{false, true} {
			raw, _ := json.Marshal(map[string]any{"service": service, "confirm_data_loss": confirm})
			if err := ValidatePayload(protocol.OperationDelete, validateResourceID, raw); err != nil {
				t.Errorf("%s: %v", service, err)
			}
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
		{"update", protocol.OperationUpdate, `{"service":"nginx"}`, "unsupported_operation"},
		{"create", protocol.OperationCreate, `{"service":"nginx"}`, "unsupported_operation"},
		{"unknown field", protocol.OperationDelete, `{"service":"nginx","extra":1}`, "invalid_payload"},
		{"wrong type", protocol.OperationDelete, `{"service":"nginx","confirm_data_loss":"yes"}`, "invalid_payload"},
		{"unlisted", protocol.OperationDelete, `{"service":"ssh"}`, "service_not_allowed"},
		{"dot dot", protocol.OperationDelete, `{"service":"../nginx"}`, "service_not_allowed"},
		{"nul", protocol.OperationDelete, `{"service":"nginx\u0000"}`, "service_not_allowed"},
		{"newline", protocol.OperationDelete, `{"service":"nginx\n--purge"}`, "service_not_allowed"},
		{"option", protocol.OperationDelete, `{"service":"--all"}`, "service_not_allowed"},
		{"long", protocol.OperationDelete, `{"service":"` + long + `"}`, "service_not_allowed"},
	} {
		err := ValidatePayload(tc.operation, validateResourceID, json.RawMessage(tc.raw))

		var verr *ValidationError
		if !errors.As(err, &verr) || verr.Code != tc.code {
			t.Errorf("%s: got %v, want code %s", tc.name, err, tc.code)
		}
	}
}
