package metrics_test

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/metrics"
	"github.com/mikho/LESta/agent/internal/protocol"
)

func TestValidatePayload(t *testing.T) {
	const uuid = "11111111-2222-4333-8444-555555555555"
	valid := func(localPart string) map[string]any {
		return map[string]any{
			"mail_accounts":    []map[string]any{{"resource_uuid": uuid, "domain": "example.com", "local_part": localPart}},
			"tenant_databases": []map[string]any{{"resource_uuid": uuid, "database_name": "lesta_1_shop", "stats_user": "lesta_1_shop_ro", "stats_password": strings.Repeat("a", 48)}},
			"web_resources":    []map[string]any{{"resource_uuid": uuid, "web_server": "nginx"}},
		}
	}
	withExtra := valid("info")
	withExtra["unexpected"] = true

	runValidatePayloadCases(t, []validatePayloadCase{
		{"observe", protocol.OperationObserve, valid("info"), ""},
		{"observe empty", protocol.OperationObserve, map[string]any{}, ""},
		{"unknown field", protocol.OperationObserve, withExtra, "decode"},
		{"dot dot local part", protocol.OperationObserve, valid("../root"), "invalid_local_part"},
		{"NUL in local part", protocol.OperationObserve, valid("in\x00fo"), "invalid_local_part"},
		{"newline in local part", protocol.OperationObserve, valid("in\nfo"), "invalid_local_part"},
		{"4096 byte local part", protocol.OperationObserve, valid(strings.Repeat("a", 4096) + "."), "invalid_local_part"},
		{"web server", protocol.OperationObserve, map[string]any{"web_resources": []map[string]any{{"resource_uuid": uuid, "web_server": "lighttpd"}}}, "invalid_web_server"},
		{"unsupported operation", protocol.OperationCreate, valid("info"), "unsupported_operation"},
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

			err := metrics.ValidatePayload(tc.op, "11111111-2222-4333-8444-555555555555", raw)

			var ve *metrics.ValidationError

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
