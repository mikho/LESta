package nginx

import (
	"bytes"
	"encoding/json"
	"fmt"
	"net"
	"regexp"
	"strings"
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
	// AccountID, AccountUsername, and PhpSocket are new as of
	// web.php-fpm.v1. AccountUsername lets renderVhost compute this
	// domain's own per-domain docroot
	// (AccountsRoot/{AccountUsername}/domains/{ResourceID}/public) the
	// same way web.php-fpm.v1 itself does, both sides independently
	// deriving the identical path from the same two inputs -- keyed by
	// username, not AccountID, so it lands inside the same account's own
	// SFTP chroot root (identity's own AccountsRoot/<username>).
	// AccountID is kept only because it is already a required, validated
	// field; nothing in this package derives a path from it. PhpSocket,
	// when non-empty, selects the real content+FastCGI template variant
	// over the existing marker-only default (see template.go's own
	// renderVhost doc comment for why this stays opt-in rather than
	// replacing the default template for every domain), and also gates
	// docroot.go's own ensureDocrootPrivileged call.
	AccountID       int    `json:"account_id"`
	AccountUsername string `json:"account_username"`
	PhpSocket       string `json:"php_socket"`
	// AdminerSocket, when non-empty, is tools.adminer.v1's own fixed,
	// node-wide PHP-FPM pool socket -- never this domain's own PhpSocket,
	// and never scoped per-resource the way PhpSocket is. It is an
	// agent-trusted literal computed Laravel-side
	// (WebDomain::toProvisioningPayload), not tenant input, so it is only
	// ever validated as empty-or-absolute here, never parsed for meaning.
	// Selects the /__lesta-adminer__ location block in php.conf.tmpl (see
	// template.go); has no effect on any other template, since Adminer
	// only ever rides on a domain that is also PHP-enabled (see
	// renderVhost's own doc comment).
	AdminerSocket string `json:"adminer_socket"`
	// WebmailSocket, when non-empty, is mail.webmail.v1's own fixed,
	// node-wide Roundcube PHP-FPM pool socket. Laravel sets it only on the
	// one WebDomain whose name is this node's own mail hostname (see
	// WebDomain::resolveWebmailSocket), and only once that domain has a
	// certificate. Selects webmail.conf.tmpl, which serves Roundcube at "/"
	// instead of the domain's own content.
	WebmailSocket string `json:"webmail_socket"`
	SSL           SSL    `json:"ssl"`
	Suspended     bool   `json:"suspended"`
	// WafMode is "", "off", "detect" (ModSecurity logs only) or "block".
	// WafExcludedRules are CRS rule ids removed for this domain only; they
	// are integers so nothing a tenant types ever reaches the config as text.
	WafMode          string `json:"waf_mode"`
	WafExcludedRules []int  `json:"waf_excluded_rules"`
	// WafPreset is "", "none" or "wordpress": a fixed, built-in set of rule
	// exclusions scoped to the CMS's admin and REST paths (see waf.tmpl).
	WafPreset string `json:"waf_preset"`
	// HotlinkProtection, when true, makes image requests whose Referer is
	// another site answer 403. HotlinkAllowedHosts are extra hostnames (and
	// their subdomains) allowed to embed; the domain's own names are always
	// allowed. Hostnames only, validated against hostnamePattern, so nothing
	// a tenant types reaches the config as anything but a hostname.
	HotlinkProtection   bool     `json:"hotlink_protection"`
	HotlinkAllowedHosts []string `json:"hotlink_allowed_hosts"`
	// IpRules is the owning account's IP access list, rendered into every
	// one of the account's vhosts: allow entries first, then deny entries,
	// nginx's first match wins, and anything unmatched is allowed.
	IpRules []IpRule `json:"ip_rules"`
	// Redirects are this domain's own URL redirects, rendered before every
	// other location so they win over PHP and the hotlink rule.
	Redirects []Redirect `json:"redirects"`
}

const maxHotlinkAllowedHosts = 50

// IpRule is one allow or deny entry from the account's IP access list. Cidr is
// a single address or a CIDR range; it is parsed and re-rendered from the
// parsed value, so nothing a tenant types reaches the config as text.
type IpRule struct {
	Action string `json:"action"`
	Cidr   string `json:"cidr"`
}

const maxIpRules = 200

// Redirect is one per-domain URL redirect. Source is an absolute path; with
// Prefix, everything under it is redirected and the rest of the path is
// appended to Target. Source and Target are validated against strict patterns
// that exclude every character nginx treats specially, so they reach the
// config only as plain path and URL text.
type Redirect struct {
	Source string `json:"source"`
	Target string `json:"target"`
	Status int    `json:"status"`
	Prefix bool   `json:"prefix"`
}

const maxRedirects = 100

var (
	redirectSourcePattern = regexp.MustCompile(`^/[A-Za-z0-9._~%+/-]{0,198}$`)
	redirectTargetPattern = regexp.MustCompile(`^(?:https?://[A-Za-z0-9.-]{1,253}(?::[0-9]{1,5})?)?(?:/[A-Za-z0-9._~%+=&?#:@!*,/-]{0,498})?$`)
)

const maxWafExcludedRules = 100

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

	if p.AccountID <= 0 {
		return Payload{}, &ValidationError{Code: "invalid_account_id", Message: "account_id must be a positive integer", Field: "account_id"}
	}

	if p.PhpSocket != "" && !docrootUsernamePattern.MatchString(p.AccountUsername) {
		return Payload{}, &ValidationError{Code: "invalid_account_username", Message: "account_username is required and must be a valid system username when php_socket is set", Field: "account_username"}
	}

	if p.WebmailSocket != "" && !strings.HasPrefix(p.WebmailSocket, "/") {
		return Payload{}, &ValidationError{Code: "invalid_webmail_socket", Message: "webmail_socket must be an absolute path when set", Field: "webmail_socket"}
	}

	if p.AdminerSocket != "" && !strings.HasPrefix(p.AdminerSocket, "/") {
		return Payload{}, &ValidationError{Code: "invalid_adminer_socket", Message: "adminer_socket must be an absolute path when set", Field: "adminer_socket"}
	}

	switch p.WafMode {
	case "", "off", "detect", "block":
	default:
		return Payload{}, &ValidationError{Code: "invalid_waf_mode", Message: "waf_mode must be off, detect or block", Field: "waf_mode"}
	}

	switch p.WafPreset {
	case "", "none", "wordpress":
	default:
		return Payload{}, &ValidationError{Code: "invalid_waf_preset", Message: "waf_preset must be none or wordpress", Field: "waf_preset"}
	}

	if len(p.Redirects) > maxRedirects {
		return Payload{}, &ValidationError{Code: "invalid_redirects", Message: fmt.Sprintf("at most %d redirects may be set", maxRedirects), Field: "redirects"}
	}

	for i, redirect := range p.Redirects {
		field := fmt.Sprintf("redirects[%d]", i)

		switch {
		case !redirectSourcePattern.MatchString(redirect.Source) || strings.Contains(redirect.Source, "//") || strings.Contains(redirect.Source, ".."):
			return Payload{}, &ValidationError{Code: "invalid_redirects", Message: "redirect source must be a plain absolute path", Field: field + ".source"}
		case redirect.Target == "" || !redirectTargetPattern.MatchString(redirect.Target):
			return Payload{}, &ValidationError{Code: "invalid_redirects", Message: "redirect target must be an http(s) URL or an absolute path", Field: field + ".target"}
		case redirect.Status != 301 && redirect.Status != 302:
			return Payload{}, &ValidationError{Code: "invalid_redirects", Message: "redirect status must be 301 or 302", Field: field + ".status"}
		case redirect.Target == redirect.Source:
			return Payload{}, &ValidationError{Code: "invalid_redirects", Message: "a redirect cannot point at its own source", Field: field + ".target"}
		}
	}

	if len(p.IpRules) > maxIpRules {
		return Payload{}, &ValidationError{Code: "invalid_ip_rules", Message: fmt.Sprintf("at most %d IP rules may be set", maxIpRules), Field: "ip_rules"}
	}

	for i, rule := range p.IpRules {
		if rule.Action != "allow" && rule.Action != "deny" {
			return Payload{}, &ValidationError{Code: "invalid_ip_rules", Message: "ip rule action must be allow or deny", Field: fmt.Sprintf("ip_rules[%d].action", i)}
		}

		if _, ok := normalizeIpOrCidr(rule.Cidr); !ok {
			return Payload{}, &ValidationError{Code: "invalid_ip_rules", Message: "ip rule address must be an IP address or a CIDR range", Field: fmt.Sprintf("ip_rules[%d].cidr", i)}
		}
	}

	if len(p.HotlinkAllowedHosts) > maxHotlinkAllowedHosts {
		return Payload{}, &ValidationError{Code: "invalid_hotlink_allowed_hosts", Message: fmt.Sprintf("at most %d hosts may be allowed", maxHotlinkAllowedHosts), Field: "hotlink_allowed_hosts"}
	}

	for i, host := range p.HotlinkAllowedHosts {
		if !hostnamePattern.MatchString(host) {
			return Payload{}, &ValidationError{Code: "invalid_hotlink_allowed_hosts", Message: "allowed host is not a valid hostname", Field: fmt.Sprintf("hotlink_allowed_hosts[%d]", i)}
		}
	}

	if len(p.WafExcludedRules) > maxWafExcludedRules {
		return Payload{}, &ValidationError{Code: "invalid_waf_excluded_rules", Message: fmt.Sprintf("at most %d rule ids may be excluded", maxWafExcludedRules), Field: "waf_excluded_rules"}
	}

	for i, id := range p.WafExcludedRules {
		if id < 1 || id > 999999999 {
			return Payload{}, &ValidationError{Code: "invalid_waf_excluded_rules", Message: "rule ids must be positive integers below 1000000000", Field: fmt.Sprintf("waf_excluded_rules[%d]", i)}
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

// normalizeIpOrCidr parses raw as an IP address or a CIDR range and returns
// its canonical form, which is what the template renders.
func normalizeIpOrCidr(raw string) (string, bool) {
	if strings.Contains(raw, "/") {
		_, network, err := net.ParseCIDR(raw)
		if err != nil {
			return "", false
		}

		return network.String(), true
	}

	ip := net.ParseIP(raw)
	if ip == nil {
		return "", false
	}

	return ip.String(), true
}

// ipAddresses returns the canonical addresses of the payload's rules for one
// action, in the order given. ParsePayload has already validated them.
func (p Payload) ipAddresses(action string) []string {
	var out []string

	for _, rule := range p.IpRules {
		if rule.Action != action {
			continue
		}

		if normalized, ok := normalizeIpOrCidr(rule.Cidr); ok {
			out = append(out, normalized)
		}
	}

	return out
}

// redirectRule is a Redirect prepared for the template: Pattern is Source as a
// regular expression prefix (only "." needs escaping, the pattern excludes
// every other metacharacter), and a prefix rule always ends in a slash.
type redirectRule struct {
	Source  string
	Pattern string
	Target  string
	Status  int
	Prefix  bool
}

// redirectRules prepares the payload's redirects for rendering.
func (p Payload) redirectRules() []redirectRule {
	rules := make([]redirectRule, 0, len(p.Redirects))

	for _, redirect := range p.Redirects {
		source := redirect.Source
		target := redirect.Target

		if redirect.Prefix {
			if !strings.HasSuffix(source, "/") {
				source += "/"
			}

			if !strings.HasSuffix(target, "/") {
				target += "/"
			}
		}

		rules = append(rules, redirectRule{
			Source:  source,
			Pattern: strings.ReplaceAll(strings.ReplaceAll(source, ".", `\.`), "+", `\+`),
			Target:  target,
			Status:  redirect.Status,
			Prefix:  redirect.Prefix,
		})
	}

	return rules
}
