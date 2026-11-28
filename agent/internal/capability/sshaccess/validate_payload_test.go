package sshaccess

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/protocol"
)

const validateResourceID = "00000000-0000-4000-8000-000000000001"

func TestValidatePayloadAcceptsOpenAndClose(t *testing.T) {
	for _, raw := range []string{`{"open":true}`, `{"open":false}`} {
		if err := ValidatePayload(protocol.OperationUpdate, validateResourceID, json.RawMessage(raw)); err != nil {
			t.Errorf("%s: %v", raw, err)
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
		{"delete", protocol.OperationDelete, `{"open":true}`, "unsupported_operation"},
		{"create", protocol.OperationCreate, `{"open":true}`, "unsupported_operation"},
		{"missing open", protocol.OperationUpdate, `{}`, "invalid_payload"},
		{"null open", protocol.OperationUpdate, `{"open":null}`, "invalid_payload"},
		{"string open", protocol.OperationUpdate, `{"open":"true"}`, "invalid_payload"},
		{"unknown field", protocol.OperationUpdate, `{"open":true,"port":2222}`, "invalid_payload"},
		{"nul", protocol.OperationUpdate, `{"open":true,"x\u0000":1}`, "invalid_payload"},
		{"newline", protocol.OperationUpdate, "{\"open\":true,\"\\n\":1}", "invalid_payload"},
		{"long", protocol.OperationUpdate, `{"open":true,"` + long + `":1}`, "invalid_payload"},
		{"not json", protocol.OperationUpdate, `../`, "invalid_payload"},
	} {
		err := ValidatePayload(tc.operation, validateResourceID, json.RawMessage(tc.raw))

		var verr *ValidationError
		if !errors.As(err, &verr) || verr.Code != tc.code {
			t.Errorf("%s: got %v, want code %s", tc.name, err, tc.code)
		}
	}
}
