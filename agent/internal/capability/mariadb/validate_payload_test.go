package mariadb_test

import (
	"encoding/json"
	"errors"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/mariadb"
	"github.com/mikho/LESta/agent/internal/protocol"
)

func TestValidatePayload(t *testing.T) {
	password := strings.Repeat("a", 48)
	withUser := func(name string, userPassword *string) map[string]any {
		p := tenantPayload("lesta_1_shop", "lesta_1_shop", &password, false)
		user := map[string]any{"name": name, "access": "full"}
		if userPassword != nil {
			user["password"] = *userPassword
		}
		p["users"] = []map[string]any{user}

		return p
	}
	withExtra := tenantPayload("lesta_1_shop", "lesta_1_shop", &password, false)
	withExtra["unexpected"] = true

	runValidatePayloadCases(t, []validatePayloadCase{
		{"create", protocol.OperationCreate, withUser("lesta_1_shop_app", &password), ""},
		{"update with password", protocol.OperationUpdate, tenantPayload("lesta_1_shop", "lesta_1_shop", &password, false), ""},
		{"update without password", protocol.OperationUpdate, tenantPayload("lesta_1_shop", "lesta_1_shop", nil, false), ""},
		{"update with a passwordless user", protocol.OperationUpdate, withUser("lesta_1_shop_app", nil), ""},
		{"suspend", protocol.OperationSuspend, tenantPayload("lesta_1_shop", "lesta_1_shop", nil, true), ""},
		{"unsuspend", protocol.OperationUnsuspend, tenantPayload("lesta_1_shop", "lesta_1_shop", nil, false), ""},
		{"delete", protocol.OperationDelete, tenantPayload("lesta_1_shop", "lesta_1_shop", nil, false), ""},
		{"observe", protocol.OperationObserve, tenantPayload("lesta_1_shop", "lesta_1_shop", nil, false), ""},
		{"create without password", protocol.OperationCreate, tenantPayload("lesta_1_shop", "lesta_1_shop", nil, false), "password_required"},
		{"create with a passwordless user", protocol.OperationCreate, withUser("lesta_1_shop_app", nil), "user_password_required"},
		{"suspend with password", protocol.OperationSuspend, tenantPayload("lesta_1_shop", "lesta_1_shop", &password, true), "password_not_allowed"},
		{"delete with password", protocol.OperationDelete, tenantPayload("lesta_1_shop", "lesta_1_shop", &password, false), "password_not_allowed"},
		{"observe with password", protocol.OperationObserve, tenantPayload("lesta_1_shop", "lesta_1_shop", &password, false), "password_not_allowed"},
		{"suspend not suspended", protocol.OperationSuspend, tenantPayload("lesta_1_shop", "lesta_1_shop", nil, false), "suspended_mismatch"},
		{"unsuspend suspended", protocol.OperationUnsuspend, tenantPayload("lesta_1_shop", "lesta_1_shop", nil, true), "suspended_mismatch"},
		{"unknown field", protocol.OperationCreate, withExtra, "decode"},
		{"dot dot name", protocol.OperationCreate, tenantPayload("../mysql", "../mysql", &password, false), "invalid_database_name"},
		{"NUL in name", protocol.OperationDelete, tenantPayload("lesta_1_shop\x00", "lesta_1_shop\x00", nil, false), "invalid_database_name"},
		{"newline in user", protocol.OperationCreate, withUser("lesta_1_shop_app\n", &password), "invalid_user_name"},
		{"4096 byte name", protocol.OperationCreate, tenantPayload("lesta_1_"+strings.Repeat("a", 4096), "lesta_1_shop", &password, false), "invalid_database_name"},
		{"unsupported operation", protocol.OperationRestore, tenantPayload("lesta_1_shop", "lesta_1_shop", nil, false), "unsupported_operation"},
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

			err := mariadb.ValidatePayload(tc.op, "11111111-2222-4333-8444-555555555555", raw)

			var ve *mariadb.ValidationError

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
