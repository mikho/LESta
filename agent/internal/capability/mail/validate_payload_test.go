package mail_test

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/mail"
	"github.com/mikho/LESta/agent/internal/protocol"
)

func TestValidatePayload(t *testing.T) {
	password := strings.Repeat("a", 48)
	valid := func(domain, localPart string) map[string]any {
		return domainPayload(domain, false, false, false, []map[string]any{account(localPart, &password)}, false)
	}
	withExtra := valid("example.com", "info")
	withExtra["unexpected"] = true

	runValidatePayloadCases(t, []validatePayloadCase{
		{"create", protocol.OperationCreate, valid("example.com", "info"), ""},
		{"update", protocol.OperationUpdate, valid("example.com", "info"), ""},
		{"suspend", protocol.OperationSuspend, domainPayload("example.com", false, false, false, nil, true), ""},
		{"unsuspend", protocol.OperationUnsuspend, valid("example.com", "info"), ""},
		{"delete", protocol.OperationDelete, valid("example.com", "info"), ""},
		{"observe ignores the payload", protocol.OperationObserve, json.RawMessage(`null`), ""},
		{"unknown field", protocol.OperationCreate, withExtra, "decode"},
		{"dot dot domain", protocol.OperationCreate, valid("../etc", "info"), "invalid_domain"},
		{"NUL in domain", protocol.OperationUpdate, valid("exa\x00mple.com", "info"), "invalid_domain"},
		{"newline in local part", protocol.OperationCreate, valid("example.com", "in\nfo"), "invalid_local_part"},
		{"4096 byte local part", protocol.OperationCreate, valid("example.com", strings.Repeat("a", 4096)+"."), "invalid_local_part"},
		{"delete still parses", protocol.OperationDelete, valid("Example.com", "info"), "invalid_domain"},
		{"unsupported operation", protocol.OperationRestore, valid("example.com", "info"), "unsupported_operation"},
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

			err := mail.ValidatePayload(tc.op, "11111111-2222-4333-8444-555555555555", raw)

			var ve *mail.ValidationError

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
