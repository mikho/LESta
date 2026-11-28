package phpfpm

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/protocol"
)

const validatePayloadResourceID = "0b9c1f6e-3d2a-4c5b-8e7f-1a2b3c4d5e6f"

// validatePayloadFixture returns the valid payload with one top-level field replaced (or added).
func validatePayloadFixture(t *testing.T, field string, value any) json.RawMessage {
	t.Helper()

	var fields map[string]any
	if err := json.Unmarshal([]byte(`{"account_id":1,"account_username":"lesta-t1","php_version":"8.3","suspended":false}`), &fields); err != nil {
		t.Fatalf("decoding the fixture: %v", err)
	}

	if field != "" {
		fields[field] = value
	}

	raw, err := json.Marshal(fields)
	if err != nil {
		t.Fatalf("encoding the fixture: %v", err)
	}

	return raw
}

// requireValidationCode checks err the way Apply turns it into a result: "" means accepted,
// "decode" means a payload that does not decode (Apply gives no verdict for it), anything else is
// the ValidationError code Apply rejects with.
func requireValidationCode(t *testing.T, label string, err error, want string) {
	t.Helper()

	var verr *ValidationError

	switch {
	case want == "" && err != nil:
		t.Fatalf("%s: expected no error, got %v", label, err)
	case want == "":
	case err == nil:
		t.Fatalf("%s: expected %s, got no error", label, want)
	case want == "decode":
		if errors.As(err, &verr) {
			t.Fatalf("%s: expected a decode error, got validation error %s", label, verr.Code)
		}
	case !errors.As(err, &verr):
		t.Fatalf("%s: expected validation error %s, got %v", label, want, err)
	case verr.Code != want:
		t.Fatalf("%s: expected code %s, got %s (%s)", label, want, verr.Code, verr.Message)
	}
}

func TestValidatePayloadAcceptsAValidPayloadForEverySupportedOperation(t *testing.T) {
	for _, operation := range []protocol.Operation{protocol.OperationCreate, protocol.OperationUpdate, protocol.OperationSuspend, protocol.OperationUnsuspend, protocol.OperationDelete, protocol.OperationObserve} {
		err := ValidatePayload(operation, validatePayloadResourceID, validatePayloadFixture(t, "", nil))
		requireValidationCode(t, string(operation), err, "")
	}
}

func TestValidatePayloadObserveIgnoresThePayload(t *testing.T) {
	err := ValidatePayload(protocol.OperationObserve, validatePayloadResourceID, json.RawMessage(`{"unknown":true}`))
	requireValidationCode(t, "observe", err, "")
}

func TestValidatePayloadRejectsHostilePayloads(t *testing.T) {
	long := strings.Repeat("a", 4096)

	cases := []struct {
		name      string
		operation protocol.Operation
		payload   json.RawMessage
		want      string
	}{
		{"unknown field", protocol.OperationCreate, validatePayloadFixture(t, "unexpected", true), "decode"},
		{"not json", protocol.OperationUpdate, json.RawMessage(`{`), "decode"},
		{"username with ../", protocol.OperationCreate, validatePayloadFixture(t, "account_username", "../../etc"), "invalid_account_username"},
		{"username with NUL", protocol.OperationUpdate, validatePayloadFixture(t, "account_username", "lesta\x00t1"), "invalid_account_username"},
		{"username with newline", protocol.OperationSuspend, validatePayloadFixture(t, "account_username", "lesta-t1\nuser = root"), "invalid_account_username"},
		{"4096-byte username", protocol.OperationCreate, validatePayloadFixture(t, "account_username", long), "invalid_account_username"},
		{"invalid username on delete", protocol.OperationDelete, validatePayloadFixture(t, "account_username", "../x"), "invalid_account_username"},
		{"php version with newline", protocol.OperationUnsuspend, validatePayloadFixture(t, "php_version", "8.3\n"), "unsupported_php_version"},
		{"php version with ../", protocol.OperationCreate, validatePayloadFixture(t, "php_version", "../8.3"), "unsupported_php_version"},
		{"account id zero", protocol.OperationCreate, validatePayloadFixture(t, "account_id", 0), "invalid_account_id"},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			requireValidationCode(t, tc.name, ValidatePayload(tc.operation, validatePayloadResourceID, tc.payload), tc.want)
		})
	}
}

func TestValidatePayloadRejectsAnUnsupportedOperation(t *testing.T) {
	for _, operation := range []protocol.Operation{protocol.OperationRestore, protocol.Operation("purge")} {
		err := ValidatePayload(operation, validatePayloadResourceID, validatePayloadFixture(t, "", nil))
		requireValidationCode(t, string(operation), err, "unsupported_operation")
	}
}
