package apache

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net"
	"regexp"

	"github.com/mikho/LESta/agent/internal/validate"
)

// usernamePattern is the account a domain belongs to (validate.AccountUsernamePattern).
var usernamePattern = validate.AccountUsernamePattern

var hostnamePattern = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$`)

// supportedWebTemplate is the single built-in template this phase supports.
// web_template is matched via a plain Go switch wherever it selects a template
// file (see template.go): tenant input never selects which template file gets
// parsed, so any other value is rejected outright, never silently reinterpreted.
const supportedWebTemplate = "default"

// SSL mirrors WebDomain::toProvisioningPayload()'s ssl shape. Mode is parsed
// and stored but never acted on directly (renderVhost's template selection
// keys off CertificatePath being non-empty, not off Mode's own value): a
// domain can be ssl_mode=lets_encrypt long before tls.acme.v1 has actually
// issued anything, and this phase's vhost must stay HTTP-only until it has,
// or `apache2 -t` would fail validating a certificate file that was never
// provisioned. CertificatePath/PrivateKeyPath are populated by
// WebDomain::toProvisioningPayload('web.apache.v1') only once
// certificate_issued_at is set, pointing at the exact fixed paths
// tls.acme.v1 itself writes to (see internal/capability/acme's own
// Config.StateRoot doc comment).
type SSL struct {
	Mode            string `json:"mode"`
	CertificatePath string `json:"certificate_path"`
	PrivateKeyPath  string `json:"private_key_path"`
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
	// AccountID and PhpSocket are new as of web.php-fpm.v1, mirroring the
	// nginx capability's own identical fields exactly (see that package's
	// own Payload doc comment) -- decoded and validated here too since
	// DisallowUnknownFields() would otherwise reject every real payload
	// now that WebDomain::toProvisioningPayload() always sends both.
	AccountID int    `json:"account_id"`
	PhpSocket string `json:"php_socket"`
	// AccountUsername names a PHP domain's docroot (php.conf.tmpl). Adminer
	// and webmail are nginx-only (webmail lives on the node's own mail
	// hostname, and an apache-served domain has no /__lesta-adminer__
	// handoff yet), so those two are decoded, since toProvisioningPayload()
	// sends them to both web capabilities, but unused here.
	AccountUsername string `json:"account_username"`
	AdminerSocket   string `json:"adminer_socket"`
	WebmailSocket   string `json:"webmail_socket"`
	SSL             SSL    `json:"ssl"`
	Suspended       bool   `json:"suspended"`
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

	if p.AccountID <= 0 {
		return Payload{}, &ValidationError{Code: "invalid_account_id", Message: "account_id must be a positive integer", Field: "account_id"}
	}

	if (p.SSL.CertificatePath != "" || p.SSL.PrivateKeyPath != "") && !validate.IsCertificatePathFor(p.Domain, p.SSL.CertificatePath, p.SSL.PrivateKeyPath) {
		return Payload{}, &ValidationError{Code: "invalid_certificate_path", Message: "the certificate and key must be this domain's own: <root>/<domain>/fullchain.pem and privkey.pem", Field: "ssl.certificate_path"}
	}

	// Rendered into the vhost as is: only safe characters. That it is this domain's own pool is
	// checked against the operation's resource id in checkResourceBindings.
	if p.PhpSocket != "" && (!validate.IsSafePath(p.PhpSocket) || !usernamePattern.MatchString(p.AccountUsername)) {
		return Payload{}, &ValidationError{Code: "invalid_php_socket", Message: "php_socket must be an absolute path, with a valid account_username, when set", Field: "php_socket"}
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

// checkResourceBindings checks what ParsePayload cannot see alone: that the PHP socket is this
// domain's own pool (…/<resource_id>.sock), so a domain can never hand its PHP to another
// domain's pool, which would run it as that domain's account.
func checkResourceBindings(p Payload, resourceID string) *ValidationError {
	if p.PhpSocket != "" && !validate.IsPHPSocketFor(p.PhpSocket, resourceID) {
		return &ValidationError{Code: "php_socket_not_own", Message: "php_socket must be this domain's own pool socket, named after its resource id", Field: "php_socket"}
	}

	return nil
}
