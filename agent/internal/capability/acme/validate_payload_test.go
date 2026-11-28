package acme_test

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/acme"
	"github.com/mikho/LESta/agent/internal/protocol"
)

func TestValidatePayload(t *testing.T) {
	const pem = "-----BEGIN CERTIFICATE-----\nAAAA\n-----END CERTIFICATE-----\n"
	challenge := func(token string) map[string]any {
		return map[string]any{"kind": "http01_challenge", "token": token, "key_authorization": token + ".thumbprint"}
	}
	withExtra := challenge("abc")
	withExtra["unexpected"] = true

	runValidatePayloadCases(t, []validatePayloadCase{
		{"create challenge", protocol.OperationCreate, challenge("abc-DEF_123"), ""},
		{"update certificate", protocol.OperationUpdate, certificatePayload("example.com", pem, pem), ""},
		{"delete challenge", protocol.OperationDelete, challenge("abc"), ""},
		{"observe ignores the payload", protocol.OperationObserve, json.RawMessage(`null`), ""},
		{"delete certificate", protocol.OperationDelete, certificatePayload("example.com", pem, pem), "unsupported_operation"},
		{"unknown field", protocol.OperationCreate, withExtra, "decode"},
		{"dot dot token", protocol.OperationCreate, challenge("../../etc/passwd"), "invalid_token"},
		{"NUL in token", protocol.OperationDelete, challenge("abc\x00"), "invalid_token"},
		{"newline in domain", protocol.OperationCreate, certificatePayload("example.com\nevil", pem, pem), "invalid_domain"},
		{"4096 byte domain", protocol.OperationCreate, certificatePayload(strings.Repeat("a", 4096)+".com", pem, pem), "invalid_domain"},
		{"unknown kind", protocol.OperationCreate, map[string]any{"kind": "dns01"}, "invalid_kind"},
		{"unsupported operation", protocol.OperationSuspend, challenge("abc"), "unsupported_operation"},
	})
}

// validatePayloadCase is one ValidatePayload call: wantCode is "" for a payload that passes,
// "decode" for one that fails to decode, otherwise the ValidationError code Apply rejects it with.
type validatePayloadCase struct {
	name     string
	op       protocol.Operation
	payload  any
	wantCode string
}

func runValidatePayloadCases(t *testing.T, cases []validatePayloadCase) {
	t.Helper()

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			raw, ok := tc.payload.(json.RawMessage)
			if !ok {
				var err error
				if raw, err = json.Marshal(tc.payload); err != nil {
					t.Fatalf("marshalling the payload: %v", err)
				}
			}

			err := acme.ValidatePayload(tc.op, "11111111-2222-4333-8444-555555555555", raw)

			var ve *acme.ValidationError

			switch {
			case tc.wantCode == "" && err != nil:
				t.Fatalf("ValidatePayload(%s) = %v, want nil", tc.op, err)
			case tc.wantCode == "":
			case err == nil:
				t.Fatalf("ValidatePayload(%s) = nil, want %s", tc.op, tc.wantCode)
			case tc.wantCode == "decode":
				if errors.As(err, &ve) {
					t.Fatalf("ValidatePayload(%s) = validation error %s, want a decode error", tc.op, ve.Code)
				}
			case !errors.As(err, &ve):
				t.Fatalf("ValidatePayload(%s) = %v, want validation error %s", tc.op, err, tc.wantCode)
			case ve.Code != tc.wantCode:
				t.Fatalf("ValidatePayload(%s) code = %s, want %s", tc.op, ve.Code, tc.wantCode)
			}
		})
	}
}
