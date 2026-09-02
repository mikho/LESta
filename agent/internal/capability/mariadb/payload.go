package mariadb

import (
	"bytes"
	"encoding/json"
	"fmt"
	"regexp"
)

// identifierPattern matches this capability's own derived database_name/
// database_user shape exactly: TenantDatabase::deriveDatabaseName's own
// "lesta_{$account->id}_{$label}" scheme, where label is already validated
// by StoreTenantDatabaseRequest against ^[a-z][a-z0-9_]{0,32}$. Duplicated
// (not imported) per this project's established precedent (every capability
// re-derives its own identifier/hostname regex rather than sharing one) --
// defense in depth even though Laravel already enforces the label shape:
// this is the full derived identifier, not just label's own charset, so it
// also catches a malformed/forged account_id segment.
var identifierPattern = regexp.MustCompile(`^lesta_[0-9]+_[a-z][a-z0-9_]{0,32}$`)

// passwordPattern matches exactly what CreateTenantDatabase/
// RotateTenantDatabasePassword generate: bin2hex(random_bytes(24)), 48
// lowercase hex characters. This charset can never contain a quote or
// backslash, so this capability needs zero SQL-string-escaping logic for it
// at all -- rejecting anything outside this exact shape is what keeps that
// guarantee sound, not a courtesy check.
var passwordPattern = regexp.MustCompile(`^[0-9a-f]{48}$`)

// Payload is the database.tenant.v1 capability's request body: one fixed
// shape for every operation (unlike acme's two-kind payload, this capability
// only ever manages one resource shape, so there is no `kind` discriminator
// at all). Password is a pointer because it is only ever populated for
// create and the dedicated password-rotate (`update`) operation -- suspend/
// unsuspend/delete/observe payloads never carry it, mirroring
// TenantDatabase::toProvisioningPayload()'s own explicit-parameter
// invariant on the Laravel side.
type Payload struct {
	DatabaseName string  `json:"database_name"`
	DatabaseUser string  `json:"database_user"`
	Password     *string `json:"password,omitempty"`
	Suspended    bool    `json:"suspended"`
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
// capability. Verb-specific requirements (create/update require a password;
// suspend/unsuspend/delete/observe must not carry one at all) are enforced
// by capability.go's own per-verb handlers, not here: this function only
// validates the shape of whatever fields are actually present, since it has
// no operation context of its own to judge against.
func ParsePayload(raw json.RawMessage) (Payload, error) {
	var p Payload

	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&p); err != nil {
		return Payload{}, fmt.Errorf("decoding mariadb payload: %w", err)
	}

	if !identifierPattern.MatchString(p.DatabaseName) {
		return Payload{}, &ValidationError{Code: "invalid_database_name", Message: "database_name does not match the expected lesta_<account_id>_<label> shape", Field: "database_name"}
	}

	if !identifierPattern.MatchString(p.DatabaseUser) {
		return Payload{}, &ValidationError{Code: "invalid_database_user", Message: "database_user does not match the expected lesta_<account_id>_<label> shape", Field: "database_user"}
	}

	if p.DatabaseUser != p.DatabaseName {
		return Payload{}, &ValidationError{Code: "database_user_mismatch", Message: "database_user must always equal database_name", Field: "database_user"}
	}

	if p.Password != nil && !passwordPattern.MatchString(*p.Password) {
		return Payload{}, &ValidationError{Code: "invalid_password", Message: "password must be exactly 48 lowercase hex characters", Field: "password"}
	}

	return p, nil
}

// requirePassword returns a *ValidationError if p has no password -- used by
// create/update, the only two verbs whose DDL needs one.
func (p Payload) requirePassword() *ValidationError {
	if p.Password == nil {
		return &ValidationError{Code: "password_required", Message: "password is required for this operation", Field: "password"}
	}

	return nil
}

// forbidPassword returns a *ValidationError if p carries a password -- used
// by suspend/unsuspend/delete/observe, which must never receive one at all
// (see this package's own doc comment and TenantDatabase::
// toProvisioningPayload()'s explicit-parameter invariant on the Laravel
// side). Defense in depth: Laravel already never sends one for these verbs,
// but this capability does not trust that from the wire alone.
func (p Payload) forbidPassword() *ValidationError {
	if p.Password != nil {
		return &ValidationError{Code: "password_not_allowed", Message: "password must not be present for this operation", Field: "password"}
	}

	return nil
}

// marshalMeta encodes p for generation.Store's own "content" role, with
// Password always redacted first regardless of whether it was actually
// present on this particular operation. This is a real secret-at-rest
// concern, not a style preference: generation.Store persists its "content"
// argument as a plain 0644 file under StateRoot (see
// internal/generation.Store.Activate), and this capability's StateRoot is
// not encrypted-at-rest the way the Laravel database column is. Copy-pasting
// acme's own marshalMeta (which persists its payload unredacted, since
// ACME's cert/key material is allowed to live at rest under its own owned
// root) here would silently create a second, unencrypted, ADR-violating copy
// of the password on disk.
func (p Payload) marshalMeta() ([]byte, error) {
	redacted := p
	redacted.Password = nil

	raw, err := json.Marshal(redacted)
	if err != nil {
		return nil, fmt.Errorf("encoding generation metadata: %w", err)
	}

	return raw, nil
}
