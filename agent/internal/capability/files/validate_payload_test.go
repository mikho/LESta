package files_test

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/files"
	"github.com/mikho/LESta/agent/internal/protocol"
)

func TestValidatePayload(t *testing.T) {
	valid := func(path string) map[string]any {
		return map[string]any{"account_username": "lesta-t1", "path": "domains/example.com/public/" + path}
	}
	rename := valid("a.txt")
	rename["new_path"] = "domains/example.com/public/b.txt"
	overwrite := valid("a.txt")
	overwrite["content_base64"] = "aGVsbG8="
	withExtra := valid("a.txt")
	withExtra["unexpected"] = true
	logs := map[string]any{"account_username": "lesta-t1", "log_kind": "access", "log_mode": "tail", "log_lines": 100}

	runValidatePayloadCases(t, []validatePayloadCase{
		{"observe", protocol.OperationObserve, valid(""), ""},
		{"create", protocol.OperationCreate, valid("a.txt"), ""},
		{"rename", protocol.OperationUpdate, rename, ""},
		{"overwrite", protocol.OperationUpdate, overwrite, ""},
		{"delete", protocol.OperationDelete, valid("a.txt"), ""},
		{"observe logs", protocol.OperationObserve, logs, ""},
		{"logs only observed", protocol.OperationDelete, logs, "unsupported_operation"},
		{"update without change", protocol.OperationUpdate, valid("a.txt"), "invalid_update"},
		{"unknown field", protocol.OperationCreate, withExtra, "decode"},
		{"dot dot", protocol.OperationDelete, valid("../../../etc/passwd"), "invalid_path"},
		{"NUL", protocol.OperationCreate, valid("a\x00.txt"), "invalid_path"},
		{"newline in user", protocol.OperationCreate, map[string]any{"account_username": "lesta-t1\nroot", "path": "a"}, "invalid_account_username"},
		{"4096 byte path", protocol.OperationCreate, valid(strings.Repeat("a", 4096)), "path_too_long"},
		{"bad payload before operation", protocol.OperationSuspend, valid("../x"), "invalid_path"},
		{"unsupported operation", protocol.OperationSuspend, valid("a.txt"), "unsupported_operation"},
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

			err := files.ValidatePayload(tc.op, "11111111-2222-4333-8444-555555555555", raw)

			var ve *files.ValidationError

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
