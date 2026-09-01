package acme

import (
	"bytes"
	"encoding/json"
	"fmt"
	"regexp"
	"strings"
)

const (
	// KindHTTP01Challenge stages (create) or removes (delete) an HTTP-01
	// challenge key-authorization file at StateRoot/http-01/<token>.
	KindHTTP01Challenge = "http01_challenge"
	// KindCertificate writes (create/update) an issued certificate bundle at
	// StateRoot/certs/<domain>/{fullchain,privkey}.pem. There is no delete
	// for this kind this phase (certificate revocation is out of scope).
	KindCertificate = "certificate"
)

// tokenPattern matches an ACME HTTP-01 token's own charset (base64url,
// unpadded, per RFC 8555 §8.3): rejecting anything else defends this
// capability's own token-derived filename against path traversal (a token
// containing "/" or ".." could otherwise escape StateRoot/http-01).
var tokenPattern = regexp.MustCompile(`^[A-Za-z0-9_-]+$`)

// hostnamePattern matches a dot-separated ASCII hostname: labels of
// alphanumerics and hyphens (never leading/trailing a label with a hyphen),
// at least two labels. Deliberately duplicated from the nginx/apache/bind9
// capabilities' own identical pattern rather than shared/imported, matching
// the "duplicate, don't import" precedent those packages already established
// for this exact regex.
var hostnamePattern = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$`)

// Payload is the tls.acme.v1 capability's request body. Kind discriminates
// between the two resource kinds this capability manages; a well-formed
// caller populates only the fields relevant to that kind (the other kind's
// fields are left zero-valued). There is no committed JSON Schema for this
// payload shape yet.
//
// The ACME *account* key that signs requests to the Certificate Authority
// never appears here, or anywhere reachable from this struct: it lives only
// in Laravel's own AcmeAccount model, encrypted at rest, and is never
// included in a ProvisioningOperation payload. FullChainPEM/PrivateKeyPEM
// below are a different thing the ADR's restriction does not cover -- a
// domain's OWN issued certificate/private key, which nginx needs in
// cleartext on disk to terminate TLS, so it travels through a normal payload
// like any other desired-state field.
type Payload struct {
	Kind string `json:"kind"`

	// Token and KeyAuthorization are populated for Kind ==
	// KindHTTP01Challenge, on both create and delete (the PHP job always
	// has both values in scope for the lifetime of one challenge attempt,
	// so it always supplies both; delete only ever acts on Token).
	Token            string `json:"token"`
	KeyAuthorization string `json:"key_authorization"`

	// Domain, FullChainPEM, and PrivateKeyPEM are populated for Kind ==
	// KindCertificate.
	Domain        string `json:"domain"`
	FullChainPEM  string `json:"full_chain_pem"`
	PrivateKeyPEM string `json:"private_key_pem"`
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
		return Payload{}, fmt.Errorf("decoding acme payload: %w", err)
	}

	switch p.Kind {
	case KindHTTP01Challenge:
		if p.Token == "" || !tokenPattern.MatchString(p.Token) {
			return Payload{}, &ValidationError{Code: "invalid_token", Message: "token must be a non-empty base64url string for kind=http01_challenge", Field: "token"}
		}
		if p.KeyAuthorization == "" {
			return Payload{}, &ValidationError{Code: "invalid_key_authorization", Message: "key_authorization must not be empty for kind=http01_challenge", Field: "key_authorization"}
		}
	case KindCertificate:
		if p.Domain == "" || !hostnamePattern.MatchString(p.Domain) {
			return Payload{}, &ValidationError{Code: "invalid_domain", Message: "domain is not a valid hostname for kind=certificate", Field: "domain"}
		}
		if !strings.Contains(p.FullChainPEM, "-----BEGIN") {
			return Payload{}, &ValidationError{Code: "invalid_certificate", Message: "full_chain_pem does not look like PEM-encoded certificate data", Field: "full_chain_pem"}
		}
		if !strings.Contains(p.PrivateKeyPEM, "-----BEGIN") {
			return Payload{}, &ValidationError{Code: "invalid_certificate", Message: "private_key_pem does not look like PEM-encoded key data", Field: "private_key_pem"}
		}
	default:
		return Payload{}, &ValidationError{
			Code:    "invalid_kind",
			Message: fmt.Sprintf("kind %q is not supported; must be %q or %q", p.Kind, KindHTTP01Challenge, KindCertificate),
			Field:   "kind",
		}
	}

	return p, nil
}

// marshalMeta encodes p for generation.Store's own "content" role (see
// capability.go's recordGenerationAndBuildResult): the exact payload a
// generation was applied from, so a future observe or forensic read can see
// which kind/token/domain a given generation represents without needing a
// separate sidecar format.
func (p Payload) marshalMeta() ([]byte, error) {
	raw, err := json.Marshal(p)
	if err != nil {
		return nil, fmt.Errorf("encoding generation metadata: %w", err)
	}

	return raw, nil
}
