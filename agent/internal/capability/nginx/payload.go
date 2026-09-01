package nginx

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
// always has a TLD-shaped tail). WebDomain::normalizeDomain already lower-cases
// and IDN-converts on the Laravel side, so this only needs to validate the
// canonical ASCII form it is handed.
var hostnamePattern = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$`)

// supportedWebTemplates is the nginx capability's own allow-list of built-in
// templates: "default" (nginx renders the tenant's own content, as every
// prior phase did) and "apache-proxy" (the "both" web profile's proxy leg --
// nginx renders a reverse proxy vhost pointing at the node's own Apache
// backend instead of any tenant content of its own; see template.go's
// apache_proxy.conf.tmpl). web_template is matched via a plain Go switch
// wherever it selects a template file (see template.go): tenant input never
// selects which template file gets parsed, so any other value is rejected
// outright, never silently reinterpreted. apache/payload.go's own
// supportedWebTemplate is deliberately untouched by this: Apache's own
// capability still only ever accepts "default", since it is always the
// content-rendering side, never the proxy side.
var supportedWebTemplates = map[string]bool{
	"default":      true,
	"apache-proxy": true,
}

// SSL mirrors WebDomain::toProvisioningPayload()'s ssl shape. Mode is parsed
// and stored but never acted on directly (renderVhost's template selection
// keys off CertificatePath being non-empty, not off Mode's own value): a
// domain can be ssl_mode=lets_encrypt long before tls.acme.v1 has actually
// issued anything, and this phase's vhost must stay HTTP-only until it has,
// or `nginx -t` would fail validating a certificate file that was never
// provisioned. CertificatePath/PrivateKeyPath are populated by
// WebDomain::toProvisioningPayload('web.nginx.v1') only once
// certificate_issued_at is set, pointing at the exact fixed paths
// tls.acme.v1 itself writes to (see internal/capability/acme's own
// Config.StateRoot doc comment).
type SSL struct {
	Mode            string `json:"mode"`
	CertificatePath string `json:"certificate_path"`
	PrivateKeyPath  string `json:"private_key_path"`
}

// Payload is the web.nginx.v1 capability's request body, matching
// WebDomain::toProvisioningPayload()'s prose shape exactly. There is no
// committed JSON Schema for this payload shape yet.
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
		return Payload{}, fmt.Errorf("decoding nginx payload: %w", err)
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

	if !supportedWebTemplates[p.WebTemplate] {
		return Payload{}, &ValidationError{
			Code:    "unsupported_web_template",
			Message: fmt.Sprintf("web_template %q is not supported; supported values this phase: %q, %q", p.WebTemplate, "default", "apache-proxy"),
			Field:   "web_template",
		}
	}

	return p, nil
}
