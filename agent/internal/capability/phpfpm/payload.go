package phpfpm

import (
	"bytes"
	"encoding/json"
	"fmt"

	"github.com/mikho/LESta/agent/internal/capability/fragment"
	"github.com/mikho/LESta/agent/internal/validate"
)

// usernamePattern is the user a pool runs as: an account's Linux user
// (validate.AccountUsernamePattern), never a system user such as mysql or the agent's own.
var usernamePattern = validate.AccountUsernamePattern

// Payload is the web.php-fpm.v1 capability's request body, matching
// WebDomain::toPhpFpmProvisioningPayload()'s prose shape exactly.
type Payload struct {
	AccountID       int    `json:"account_id"`
	AccountUsername string `json:"account_username"`
	PhpVersion      string `json:"php_version"`
	Suspended       bool   `json:"suspended"`
}

// ValidationError is a well-formed payload rejection: a schema-shaped (code,
// message, field) triple the caller turns directly into a rejected
// ResultEnvelope. It is never a Go error representing "no verdict was
// reached".
type ValidationError = fragment.ValidationError

// ParsePayload decodes and validates raw as a Payload. Unknown fields are a
// hard decode error, matching the envelope decode discipline every other
// capability in this module already follows.
func ParsePayload(raw json.RawMessage) (Payload, error) {
	var p Payload

	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&p); err != nil {
		return Payload{}, fmt.Errorf("decoding php-fpm payload: %w", err)
	}

	if p.AccountID <= 0 {
		return Payload{}, &ValidationError{Code: "invalid_account_id", Message: "account_id must be a positive integer", Field: "account_id"}
	}

	if !usernamePattern.MatchString(p.AccountUsername) {
		return Payload{}, &ValidationError{Code: "invalid_account_username", Message: "account_username is not a valid system username", Field: "account_username"}
	}

	if !supportedPhpVersions[p.PhpVersion] {
		return Payload{}, &ValidationError{Code: "unsupported_php_version", Message: fmt.Sprintf("php_version %q is not supported", p.PhpVersion), Field: "php_version"}
	}

	return p, nil
}
