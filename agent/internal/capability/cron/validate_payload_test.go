package cron_test

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/cron"
	"github.com/mikho/LESta/agent/internal/protocol"
)

func TestValidatePayload(t *testing.T) {
	valid := func(command, runAs string) map[string]any {
		return cronPayloadRunAs("*/5", "*", "*", "*", "*", command, false, runAs)
	}
	withExtra := valid("echo hello", "lesta-t1")
	withExtra["unexpected"] = true

	runValidatePayloadCases(t, []validatePayloadCase{
		{"create", protocol.OperationCreate, valid("echo hello", "lesta-t1"), ""},
		{"update", protocol.OperationUpdate, valid("echo hello", "lesta-t1"), ""},
		{"suspend", protocol.OperationSuspend, cronPayloadRunAs("0", "1", "*", "*", "*", "echo hello", true, "lesta-t1"), ""},
		{"unsuspend", protocol.OperationUnsuspend, valid("echo hello", "lesta-t1"), ""},
		{"delete", protocol.OperationDelete, valid("echo hello", "lesta-cron"), ""},
		{"observe ignores the payload", protocol.OperationObserve, json.RawMessage(`null`), ""},
		{"unknown field", protocol.OperationCreate, withExtra, "decode"},
		{"dot dot run_as", protocol.OperationCreate, valid("echo hello", "../root"), "invalid_run_as"},
		{"root run_as", protocol.OperationUpdate, valid("echo hello", "root"), "invalid_run_as"},
		{"NUL in run_as", protocol.OperationCreate, valid("echo hello", "lesta-t1\x00"), "invalid_run_as"},
		{"newline in command", protocol.OperationCreate, valid("echo a\n* * * * * root id", "lesta-t1"), "invalid_command"},
		{"4096 byte command", protocol.OperationCreate, valid(strings.Repeat("a", 4096), "lesta-t1"), "invalid_command"},
		{"bad schedule", protocol.OperationCreate, cronPayloadRunAs("61", "*", "*", "*", "*", "echo hello", false, "lesta-t1"), "invalid_schedule_field"},
		{"delete still parses", protocol.OperationDelete, valid("echo hello", "www-data"), "invalid_run_as"},
		{"unsupported operation", protocol.OperationRestore, valid("echo hello", "lesta-t1"), "unsupported_operation"},
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

			err := cron.ValidatePayload(tc.op, "11111111-2222-4333-8444-555555555555", raw)

			var ve *cron.ValidationError

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
