package mail

import (
	"bytes"
	"encoding/json"
	"fmt"
	"regexp"
)

// domainPattern matches a normalized domain as MailDomain::normalizeDomain
// (Laravel) produces it: lowercase, IDN-converted ASCII/punycode, dots and
// hyphens allowed, no leading/trailing dot. Deliberately permissive on
// label-length details (the real DNS-length limits are enforced on the
// Laravel side already); this is defense in depth against a malformed or
// forged value, not the primary validation boundary.
var domainPattern = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$`)

// localPartPattern matches MailAccount.local_part as CreateMailAccount
// (Laravel) produces it: mb_strtolower(trim(...)), otherwise unvalidated on
// the Laravel side today. Scoped here to the safe, portable RFC 5321 dot-
// atom subset (no quoted strings, no consecutive dots): a real Maildir path
// segment and Exim/Dovecot lookup key, so this pattern is deliberately
// narrower than RFC 5321's full local-part grammar allows.
var localPartPattern = regexp.MustCompile(`^[a-z0-9](?:[a-z0-9._+-]*[a-z0-9])?$`)

// emailPattern matches a forward_to/catchall_email target address: a bare,
// pragmatic local@domain shape, not full RFC 5321 validation (this
// capability never sends mail to this address itself, only writes it into a
// redirect router's own data; Exim's own delivery attempt is the real
// validation authority for a genuinely malformed target).
var emailPattern = regexp.MustCompile(`^[^@\s]+@` + `[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$`)

// dkimSelectorPattern matches a DKIM selector as MailDomain::nextDkimSelector
// (Laravel) generates it: never raw tenant input, always this project's own
// fixed "lesta" + an incrementing digit shape, but validated here anyway as
// defense in depth like every other pattern in this file.
var dkimSelectorPattern = regexp.MustCompile(`^[a-z0-9][a-z0-9-]*$`)

// Account is one embedded MailAccount within a Payload, mirroring
// MailDomain::toProvisioningPayload()'s own 'accounts' array shape exactly.
// Password is a pointer because it is only ever populated for the one
// account whose password was just created or rotated in the same request
// (see MailDomain::toProvisioningPayload()'s own explicit-parameter
// invariant on the Laravel side); every other embedded account never
// carries it at all.
type Account struct {
	LocalPart        string  `json:"local_part"`
	Password         *string `json:"password,omitempty"`
	QuotaMB          *int    `json:"quota_mb"`
	ForwardTo        *string `json:"forward_to"`
	ForwardOnly      bool    `json:"forward_only"`
	AutoreplyEnabled bool    `json:"autoreply_enabled"`
	AutoreplyMessage *string `json:"autoreply_message"`
	Suspended        bool    `json:"suspended"`
}

// Payload is the mail.smtp-imap.v1 capability's request body, mirroring
// MailDomain::toProvisioningPayload()'s exact shape: one fixed shape for
// every operation, the domain as the single resource with every account
// embedded (see this package's own doc comment for why).
// DkimActiveSelector is the selector Exim actually signs outgoing mail with
// right now, mirroring MailDomain.dkim_selector on the Laravel side exactly.
// DkimPendingSelector, when present, names a second selector this apply
// should also generate a real key for and report on ResultEnvelope.Data (so
// Laravel can publish its own DNS TXT record) without switching signing to
// it yet -- the real "publish before you sign" half of selector rotation
// (see dkim.go's own doc comment). DkimRetireSelector, when present, is a
// one-shot instruction (never persisted desired state, unlike the other two
// selector fields, which is why MailDomain::toProvisioningPayload's own
// retireSelector parameter is explicit rather than reading a stored column):
// delete that selector's real key material from this node now, because
// Laravel has already finished retiring its own DNS record and rotation
// bookkeeping for it.
type Payload struct {
	Domain              string    `json:"domain"`
	AntivirusEnabled    bool      `json:"antivirus_enabled"`
	AntispamEnabled     bool      `json:"antispam_enabled"`
	DkimEnabled         bool      `json:"dkim_enabled"`
	DkimActiveSelector  string    `json:"dkim_active_selector"`
	DkimPendingSelector *string   `json:"dkim_pending_selector"`
	DkimRetireSelector  *string   `json:"dkim_retire_selector"`
	CatchallEmail       *string   `json:"catchall_email"`
	Accounts            []Account `json:"accounts"`
	Suspended           bool      `json:"suspended"`
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
// capability in this module.
func ParsePayload(raw json.RawMessage) (Payload, error) {
	var p Payload

	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&p); err != nil {
		return Payload{}, fmt.Errorf("decoding mail payload: %w", err)
	}

	if !domainPattern.MatchString(p.Domain) {
		return Payload{}, &ValidationError{Code: "invalid_domain", Message: "domain does not match the expected normalized shape", Field: "domain"}
	}

	if p.CatchallEmail != nil && !emailPattern.MatchString(*p.CatchallEmail) {
		return Payload{}, &ValidationError{Code: "invalid_catchall_email", Message: "catchall_email does not match the expected local@domain shape", Field: "catchall_email"}
	}

	if p.DkimEnabled {
		if !dkimSelectorPattern.MatchString(p.DkimActiveSelector) {
			return Payload{}, &ValidationError{Code: "invalid_dkim_active_selector", Message: "dkim_active_selector is required and must match the expected shape when dkim_enabled is true", Field: "dkim_active_selector"}
		}
	}

	if p.DkimPendingSelector != nil && !dkimSelectorPattern.MatchString(*p.DkimPendingSelector) {
		return Payload{}, &ValidationError{Code: "invalid_dkim_pending_selector", Message: "dkim_pending_selector does not match the expected shape", Field: "dkim_pending_selector"}
	}

	if p.DkimRetireSelector != nil && !dkimSelectorPattern.MatchString(*p.DkimRetireSelector) {
		return Payload{}, &ValidationError{Code: "invalid_dkim_retire_selector", Message: "dkim_retire_selector does not match the expected shape", Field: "dkim_retire_selector"}
	}

	seen := make(map[string]struct{}, len(p.Accounts))

	for i, a := range p.Accounts {
		if !localPartPattern.MatchString(a.LocalPart) {
			return Payload{}, &ValidationError{Code: "invalid_local_part", Message: fmt.Sprintf("accounts[%d].local_part does not match the expected shape", i), Field: "accounts"}
		}

		if _, dup := seen[a.LocalPart]; dup {
			return Payload{}, &ValidationError{Code: "duplicate_local_part", Message: fmt.Sprintf("accounts[%d].local_part %q is duplicated within this domain", i, a.LocalPart), Field: "accounts"}
		}
		seen[a.LocalPart] = struct{}{}

		if a.Password != nil && !passwordPattern.MatchString(*a.Password) {
			return Payload{}, &ValidationError{Code: "invalid_account_password", Message: fmt.Sprintf("accounts[%d].password must be exactly 48 lowercase hex characters", i), Field: "accounts"}
		}

		if a.ForwardTo != nil && !emailPattern.MatchString(*a.ForwardTo) {
			return Payload{}, &ValidationError{Code: "invalid_forward_to", Message: fmt.Sprintf("accounts[%d].forward_to does not match the expected local@domain shape", i), Field: "accounts"}
		}

		if a.QuotaMB != nil && *a.QuotaMB < 0 {
			return Payload{}, &ValidationError{Code: "invalid_quota", Message: fmt.Sprintf("accounts[%d].quota_mb must not be negative", i), Field: "accounts"}
		}
	}

	return p, nil
}

// passwordPattern matches exactly what CreateMailAccount/
// RotateMailAccountPassword (Laravel) generate: bin2hex(random_bytes(24)),
// 48 lowercase hex characters. This charset can never contain a quote,
// backslash, or colon, so this capability needs zero lookup-file-escaping
// logic for it at all -- rejecting anything outside this exact shape is what
// keeps that guarantee sound, not a courtesy check.
var passwordPattern = regexp.MustCompile(`^[0-9a-f]{48}$`)

// marshalMeta encodes p for this package's own generation sidecar (see
// meta.go), with every account's Password always redacted first regardless
// of whether it was actually present on this particular operation. This is a
// real secret-at-rest concern, not a style preference: the sidecar persists
// as a plain 0644 file under StateRoot, not encrypted the way the Laravel
// database column is.
func (p Payload) marshalMeta() ([]byte, error) {
	redacted := p
	redacted.Accounts = make([]Account, len(p.Accounts))

	for i, a := range p.Accounts {
		a.Password = nil
		redacted.Accounts[i] = a
	}

	raw, err := json.Marshal(redacted)
	if err != nil {
		return nil, fmt.Errorf("encoding generation metadata: %w", err)
	}

	return raw, nil
}
