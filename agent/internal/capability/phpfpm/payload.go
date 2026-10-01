package phpfpm

import (
	"bytes"
	"encoding/json"
	"fmt"
	"regexp"
)

// usernamePattern mirrors identity/payload.go's own usernamePattern exactly
// (agent/internal/capability/identity/payload.go): AccountUsername is always
// the exact system_username system.account-identity.v1 already provisioned,
// re-validated here from scratch since this is a real, separate process
// boundary this package's own security boundary must hold on its own.
var usernamePattern = regexp.MustCompile(`^[a-z][a-z0-9_-]{0,31}$`)

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
type ValidationError struct {
	Code    string
	Message string
	Field   string
}

func (e *ValidationError) Error() string {
	return fmt.Sprintf("%s: %s (field=%s)", e.Code, e.Message, e.Field)
}

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
