package apache

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net"
	"regexp"
)

// hostnamePattern matches a dot-separated ASCII hostname: labels of alphanumerics
// and hyphens (never leading/trailing a label with a hyphen), at least two labels
// (so a bare "localhost"-style single label is rejected; a real vhost domain
// always has a TLD-shaped tail). Deliberately duplicated from the nginx
// capability's identical pattern rather than shared/imported: this package does
// not depend on internal/capability/nginx, matching the same "duplicate, don't
// import" precedent bind9 already established for its own copy.
// WebDomain::normalizeDomain already lower-cases and IDN-converts on the Laravel
// side, so this only needs to validate the canonical ASCII form it is handed.
var hostnamePattern = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$`)

// supportedWebTemplate is the single built-in template this phase supports.
// web_template is matched via a plain Go switch wherever it selects a template
// file (see template.go): tenant input never selects which template file gets
// parsed, so any other value is rejected outright, never silently reinterpreted.
const supportedWebTemplate = "default"

// SSL mirrors WebDomain::toProvisioningPayload()'s ssl shape. mode is parsed and
// stored but never acted on this phase: every vhost this phase renders is
// HTTP-only, since acting on ssl.mode would need tls.acme.v1 certificates that
// don't exist yet, and would make the agent's own `apache2 -t` validation fail
// against a certificate file that was never provisioned.
type SSL struct {
	Mode string `json:"mode"`
}

// Payload is the web.apache.v1 capability's request body, matching
// WebDomain::toProvisioningPayload()'s prose shape exactly -- byte-identical to
// the nginx capability's own Payload, since toProvisioningPayload() is
// server-agnostic. There is no committed JSON Schema for this payload shape yet.
type Payload struct {
	Domain      string   `json:"domain"`
	Aliases     []string `json:"aliases"`
	IPAddress   string   `json:"ip_address"`
	WebTemplate string   `json:"web_template"`
	SSL         SSL      `json:"ssl"`
	Suspended   bool     `json:"suspended"`
}

// ValidationError is a well-formed payload rejection: a schema-shaped (code,
// message, field) triple the caller turns directly into a rejected
// ResultEnvelope. It is never a Go error representing "no verdict was reached".
type ValidationError struct {
	Code    string
	Message string
	Field   string
}

func (e *ValidationError) Error() string {
	return fmt.Sprintf("%s: %s (field=%s)", e.Code, e.Message, e.Field)
}

// ParsePayload decodes and validates raw as a Payload. Unknown fields are a hard
// decode error, matching the envelope decode discipline. Domain and every alias
// are validated against the hostname pattern before anything touches a template;
// web_template is checked against the single supported built-in.
func ParsePayload(raw json.RawMessage) (Payload, error) {
	var p Payload

	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&p); err != nil {
		return Payload{}, fmt.Errorf("decoding apache payload: %w", err)
	}

	if !hostnamePattern.MatchString(p.Domain) {
		return Payload{}, &ValidationError{Code: "invalid_domain", Message: "domain is not a valid hostname", Field: "domain"}
	}

	for i, alias := range p.Aliases {
		if !hostnamePattern.MatchString(alias) {
			return Payload{}, &ValidationError{
				Code:    "invalid_domain",
				Message: "alias is not a valid hostname",
				Field:   fmt.Sprintf("aliases[%d]", i),
			}
		}
	}

	if net.ParseIP(p.IPAddress) == nil {
		return Payload{}, &ValidationError{Code: "invalid_ip_address", Message: "ip_address is not a valid IP address", Field: "ip_address"}
	}

	if p.WebTemplate != supportedWebTemplate {
		return Payload{}, &ValidationError{
			Code:    "unsupported_web_template",
			Message: fmt.Sprintf("web_template %q is not supported; only %q is available this phase", p.WebTemplate, supportedWebTemplate),
			Field:   "web_template",
		}
	}

	return p, nil
}
