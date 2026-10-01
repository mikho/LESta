package phpfpm

import (
	"encoding/json"
	"testing"
)

func validPayloadJSON(t *testing.T) json.RawMessage {
	t.Helper()

	raw, err := json.Marshal(map[string]any{
		"account_id":       1,
		"account_username": "lesta-t1",
		"php_version":      "8.3",
		"suspended":        false,
	})
	if err != nil {
		t.Fatalf("marshaling test payload: %v", err)
	}

	return raw
}

func TestParsePayloadAcceptsAWellFormedPayload(t *testing.T) {
	p, err := ParsePayload(validPayloadJSON(t))
	if err != nil {
		t.Fatalf("expected no error, got %v", err)
	}

	if p.AccountID != 1 || p.AccountUsername != "lesta-t1" || p.PhpVersion != "8.3" || p.Suspended {
		t.Fatalf("unexpected parsed payload: %+v", p)
	}
}

func TestParsePayloadRejectsAnInvalidAccountID(t *testing.T) {
	raw, _ := json.Marshal(map[string]any{
		"account_id":       0,
		"account_username": "lesta-t1",
		"php_version":      "8.3",
	})

	_, err := ParsePayload(raw)
	requireValidationErrorCode(t, err, "invalid_account_id")
}

func TestParsePayloadRejectsAnInvalidAccountUsername(t *testing.T) {
	raw, _ := json.Marshal(map[string]any{
		"account_id":       1,
		"account_username": "Not-Valid",
		"php_version":      "8.3",
	})

	_, err := ParsePayload(raw)
	requireValidationErrorCode(t, err, "invalid_account_username")
}

func TestParsePayloadRejectsAnUnsupportedPhpVersion(t *testing.T) {
	raw, _ := json.Marshal(map[string]any{
		"account_id":       1,
		"account_username": "lesta-t1",
		"php_version":      "7.4",
	})

	_, err := ParsePayload(raw)
	requireValidationErrorCode(t, err, "unsupported_php_version")
}

func TestParsePayloadRejectsUnknownFields(t *testing.T) {
	raw, _ := json.Marshal(map[string]any{
		"account_id":       1,
		"account_username": "lesta-t1",
		"php_version":      "8.3",
		"unexpected_field": "x",
	})

	if _, err := ParsePayload(raw); err == nil {
		t.Fatal("expected an error decoding a payload with an unknown field")
	}
}

func requireValidationErrorCode(t *testing.T, err error, code string) {
	t.Helper()

	if err == nil {
		t.Fatalf("expected a validation error with code %q, got none", code)
	}

	ve, ok := err.(*ValidationError)
	if !ok {
		t.Fatalf("expected a *ValidationError, got %T: %v", err, err)
	}

	if ve.Code != code {
		t.Fatalf("expected code %q, got %q", code, ve.Code)
	}
}
