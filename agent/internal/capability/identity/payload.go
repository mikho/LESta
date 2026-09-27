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

// sshPublicKeyPattern matches a single authorized_keys line: a supported key
// type, one required base64-shaped field, and an optional comment. This
// mirrors App\Rules\ValidSshPublicKey's own type allow-list, but stays a
// shape check only -- unlike the Laravel-side rule, it does not decode the
// base64 blob and cross-check its own embedded RFC 4251 type string, since a
// malformed key here only ever fails harmlessly at real sshd config-parse
// time (validate.go's own `sshd -t`), never reaches an exec/shell call the
// way an unvalidated username would.
var sshPublicKeyPattern = regexp.MustCompile(`^(ssh-ed25519|ssh-rsa|ecdsa-sha2-nistp256|ecdsa-sha2-nistp384|ecdsa-sha2-nistp521) [A-Za-z0-9+/]+=*( .*)?$`)

// Payload is the system.account-identity.v1 capability's request body.
// create/delete's own dispatch never sends ssh_public_key at all (see
// AccountNodeIdentity::toProvisioningPayload()), decoding it as a nil
// pointer harmlessly; applyCreate/applyDelete never read it. update always
// sends it explicitly, nil meaning "no key on file" -- update always renders
// a real, syntactically valid Match block and an authorized_keys file
// either way (possibly empty), never conditionally omitting the config file
// itself, matching every other capability's own "suspended is a data
// toggle, not a rendering branch" discipline.
type Payload struct {
	Username     string  `json:"username"`
	SshPublicKey *string `json:"ssh_public_key"`
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

	if p.SshPublicKey != nil && *p.SshPublicKey != "" && !sshPublicKeyPattern.MatchString(*p.SshPublicKey) {
		return Payload{}, &ValidationError{
			Code:    "invalid_ssh_public_key",
			Message: "ssh_public_key does not look like a supported authorized_keys line",
			Field:   "ssh_public_key",
		}
	}

	return p, nil
}
