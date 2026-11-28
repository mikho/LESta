package apache

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
	if err := json.Unmarshal([]byte(`{"domain":"a.example.test","aliases":[],"ip_address":"127.0.0.1","web_template":"default","account_id":1,"account_username":"","php_socket":"","ssl":{"mode":"off"},"suspended":false}`), &fields); err != nil {
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
		{"domain with ../", protocol.OperationCreate, validatePayloadFixture(t, "domain", "../etc/passwd"), "invalid_domain"},
		{"domain with NUL", protocol.OperationUpdate, validatePayloadFixture(t, "domain", "a\x00.example.test"), "invalid_domain"},
		{"domain with newline", protocol.OperationSuspend, validatePayloadFixture(t, "domain", "a.example.test\nserver_name evil"), "invalid_domain"},
		{"4096-byte domain", protocol.OperationCreate, validatePayloadFixture(t, "domain", long+".test"), "invalid_domain"},
		{"invalid domain on delete", protocol.OperationDelete, validatePayloadFixture(t, "domain", "../x"), "invalid_domain"},
		{"account id zero", protocol.OperationUnsuspend, validatePayloadFixture(t, "account_id", 0), "invalid_account_id"},
		{"php socket with ../", protocol.OperationCreate, withSocket(t, "/run/php/8.3/../"+validatePayloadResourceID+".sock"), "invalid_php_socket"},
		{"php socket of another domain", protocol.OperationCreate, withSocket(t, "/run/php/8.3/11111111-2222-4333-8444-555555555555.sock"), "php_socket_not_own"},
		{"php socket of another domain on update", protocol.OperationUpdate, withSocket(t, "/run/php/8.3/11111111-2222-4333-8444-555555555555.sock"), "php_socket_not_own"},
		{"own php socket", protocol.OperationCreate, withSocket(t, "/run/php/8.3/"+validatePayloadResourceID+".sock"), ""},
		{"delete does not bind the socket, as Apply", protocol.OperationDelete, withSocket(t, "/run/php/8.3/11111111-2222-4333-8444-555555555555.sock"), ""},
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

// withSocket is the valid payload pointing at a PHP-FPM pool socket, with the account it runs as.
func withSocket(t *testing.T, socket string) json.RawMessage {
	t.Helper()

	var fields map[string]any
	if err := json.Unmarshal(validatePayloadFixture(t, "php_socket", socket), &fields); err != nil {
		t.Fatalf("decoding the fixture: %v", err)
	}

	fields["account_username"] = "lesta-t1"

	raw, err := json.Marshal(fields)
	if err != nil {
		t.Fatalf("encoding the fixture: %v", err)
	}

	return raw
}
