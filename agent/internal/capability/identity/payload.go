package identity

import (
	"bytes"
	"encoding/json"
	"fmt"
	"regexp"
)

// usernamePattern enforces standard Linux system-username constraints: a
// leading lowercase letter, then up to 31 further lowercase letters,
// digits, underscores, or hyphens (32 characters total, the traditional
// glibc/useradd limit). Laravel computes every username deterministically
// (see App\Actions\Cron\EnsuresAccountNodeIdentity's own
// deterministicUsername), so this pattern is never the primary safeguard,
// only defense in depth: this capability rejects anything that doesn't
// match before ever handing the value to exec.Command, matching how
// payload.go in every other capability in this module validates
// tenant-influenced content before it reaches a shell/exec call.
var usernamePattern = regexp.MustCompile(`^[a-z][a-z0-9_-]{0,31}$`)

// Payload is the system.account-identity.v1 capability's request body.
type Payload struct {
	Username string `json:"username"`
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
// hard decode error, matching the envelope decode discipline of every other
// capability.
func ParsePayload(raw json.RawMessage) (Payload, error) {
	var p Payload

	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&p); err != nil {
		return Payload{}, fmt.Errorf("decoding identity payload: %w", err)
	}

	if p.Username == "" || !usernamePattern.MatchString(p.Username) {
		return Payload{}, &ValidationError{
			Code:    "invalid_username",
			Message: "username must be a non-empty string matching ^[a-z][a-z0-9_-]{0,31}$",
			Field:   "username",
		}
	}

	return p, nil
}
