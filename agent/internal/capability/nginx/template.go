package nginx

import (
	"bytes"
	"embed"
	"fmt"
	"path"
	"text/template"
)

//go:embed templates/default.conf.tmpl templates/suspended.conf.tmpl templates/apache_proxy.conf.tmpl templates/default_ssl.conf.tmpl templates/php.conf.tmpl templates/webmail.conf.tmpl templates/error_pages.tmpl templates/waf.tmpl
var templateFS embed.FS

// suspendedHTML is the static maintenance page served for every suspended
// resource. Its content is identical for all resources (it carries no
// per-resource data), so it needs no directory of its own on disk: it is
// substituted directly into suspended.conf.tmpl's rendered `return` body at
// render time.
//
//go:embed templates/suspended.html
var suspendedHTML []byte

// vhostData is the substitution set for both templates. Every field is either
// already hostname-validated payload data or an agent-computed value; tenant
// input never selects which template *file* gets parsed (that's a plain Go
// switch in render, below), it only ever fills already-validated placeholders.
type vhostData struct {
	ResourceID string
	Domain     string
	Aliases    []string
	IPAddress  string
	Port       int
	// WebTemplate is the payload's own web_template value, threaded through
	// so renderVhost can select apache_proxy.conf.tmpl without needing a
	// separate parameter of its own. Never used inside a template body
	// itself (nothing in apache_proxy.conf.tmpl references {{.WebTemplate}});
	// it only ever drives renderVhost's own Go-level template-file selection.
	WebTemplate string
	// ProxyBackend is the "host:port" apache_proxy.conf.tmpl's proxy_pass
	// directive points at. Unused by every other template.
	ProxyBackend string
	// AcmeChallengeDir backs every template's shared
	// `.well-known/acme-challenge/` location block (see Config's own field
	// of the same name).
	AcmeChallengeDir string
	// CertificatePath and PrivateKeyPath, both non-empty, select
	// default_ssl.conf.tmpl over default.conf.tmpl (see renderVhost) and
	// back its second server block's ssl_certificate/ssl_certificate_key
	// directives. Empty for every domain with no certificate issued yet.
	CertificatePath string
	PrivateKeyPath  string
	// SSLPort backs default_ssl.conf.tmpl's second server block's listen
	// directive (see Config's own field of the same name). Unused by every
	// other template.
	SSLPort int
	// AccessLogPath backs every template's own access_log directive (see
	// Config.LogDir's own doc comment for why this is per-resource, never a
	// single combined log).
	AccessLogPath string
	// Docroot and PhpSocket are new as of web.php-fpm.v1. Docroot backs
	// php.conf.tmpl's own `root` directive (this domain's own real
	// per-domain webroot, per Config.AccountsRoot's own doc comment).
	// PhpSocket, non-empty, selects php.conf.tmpl over the existing
	// marker-only default.conf.tmpl (see renderVhost's own doc comment
	// for why this stays opt-in rather than replacing the default
	// template for every domain).
	Docroot   string
	PhpSocket string
	// AdminerSocket backs the /__lesta-adminer__ location block (see
	// Payload.AdminerSocket's own doc comment). Non-empty together with a
	// certificate, it selects webmail.conf.tmpl (the node tools host); it
	// also still renders inside php.conf.tmpl for a payload that carries
	// one, though the control plane now only sends it for the node's own
	// hostname.
	AdminerSocket string
	// WebmailSocket, non-empty together with a certificate, selects
	// webmail.conf.tmpl (see renderVhost).
	WebmailSocket string
	// FastcgiParamsPath backs php.conf.tmpl's own `include` directive as an
	// absolute path, deliberately never a bare relative `fastcgi_params`:
	// nginx resolves a relative include against its own compiled-in
	// --prefix, not against -c's own directory, so validate.go's synthetic
	// scratch-directory config (a different -c entirely) fails to find it
	// unless the path is absolute.
	FastcgiParamsPath string
	// Marker is a known string embedded in the rendered default vhost's body,
	// so a health check can assert that *this* resource answered, not just
	// that some nginx vhost is alive. It is deliberately a function of
	// ResourceID alone, never of the generation number: rendering is only
	// pure with respect to the desired state (create then suspend then
	// unsuspend must produce byte-identical output to create's own, so the
	// digest is restored) if nothing generation-specific leaks into the
	// rendered bytes. Host-header routing already guarantees a request lands
	// on the right resource's vhost; the marker only needs to confirm that,
	// not which generation rendered it.
	Marker string
	// WafMode and WafExcludedRules turn on ModSecurity for this vhost (see
	// templates/waf.tmpl); WafRulesFile is the node-wide rule set the
	// installer provides, WafAuditLog this resource's own audit log.
	WafMode          string
	WafExcludedRules []int
	WafPreset        string
	WafRulesFile     string
	WafAuditLog      string
	// SuspendedPage is suspendedHTML's content, substituted in only when
	// rendering the suspended template.
	SuspendedPage string
}

const (
	wafRulesFile = "/etc/lesta/waf/main.conf"
	wafLogDir    = "/var/log/lesta/waf"
)

// WafEnabled reports whether the waf partial renders any directives.
func (d vhostData) WafEnabled() bool {
	return d.WafMode == "detect" || d.WafMode == "block"
}

// WafEngine is the SecRuleEngine value for the domain's mode.
func (d vhostData) WafEngine() string {
	if d.WafMode == "block" {
		return "On"
	}

	return "DetectionOnly"
}

func (d vhostData) marker() string {
	return fmt.Sprintf("LESTA-MARKER resource=%s", d.ResourceID)
}

// renderVhost renders the vhost fragment for data. suspended takes priority
// over everything else and always wins when true (a suspended apache-routed
// domain shows nginx's ordinary suspended page directly, never reaching
// Apache at all, reusing 100% of the existing suspended-page mechanism);
// otherwise data.WebTemplate selects apache_proxy.conf.tmpl when it is
// "apache-proxy" (nginx is only proxying in that case, never serving real
// content of its own, so PhpSocket is irrelevant and deliberately checked
// after this case); then PhpSocket being non-empty selects the real
// content+FastCGI template over the existing marker-only default -- kept as
// its own separate, opt-in template file rather than folded into
// default.conf.tmpl, so every domain that never opts into PHP keeps
// rendering byte-identical output to before this capability existed, and
// every existing marker-based health check stays meaningful unchanged;
// falling back to default_ssl.conf.tmpl when a certificate path is present,
// and to the marker-only default content-rendering template for everything
// else. php.conf.tmpl itself is SSL-aware (as of tools.adminer.v1, which
// needs a PHP-enabled domain's own existing certificate to ride on): when
// CertificatePath is also present, it renders a second HTTPS server block
// alongside the original HTTP one, serving the identical PHP content on
// both -- no forced HTTP->HTTPS redirect, matching default_ssl.conf.tmpl's
// own disclosed "no forced redirect this phase" behavior exactly, not a new
// divergent policy. All selectors are pure functions of the payload, never of the
// requested operation's name, so create/update/suspend/unsuspend can all
// funnel through the identical rendering call.
func renderVhost(data vhostData, suspended bool) ([]byte, error) {
	data.Marker = data.marker()
	data.WafRulesFile = wafRulesFile
	data.WafAuditLog = wafLogDir + "/" + data.ResourceID + ".audit.log"

	name := "default.conf.tmpl"

	switch {
	case suspended:
		name = "suspended.conf.tmpl"
		data.SuspendedPage = string(suspendedHTML)
	case (data.WebmailSocket != "" || data.AdminerSocket != "") && data.CertificatePath != "":
		// The node tools host is checked before apache-proxy/php: this one
		// domain is the node's own hostname, serving Roundcube (webmail) and/or
		// the Adminer hand-off instead of any content of its own. It requires a
		// certificate (both carry login credentials, and this template forces
		// HTTP to HTTPS); without one, the domain falls through to its
		// ordinary template.
		name = "webmail.conf.tmpl"
	case data.WebTemplate == "apache-proxy":
		name = "apache_proxy.conf.tmpl"
	case data.PhpSocket != "":
		name = "php.conf.tmpl"
	case data.CertificatePath != "":
		name = "default_ssl.conf.tmpl"
	}

	tmplPath := path.Join("templates", name)

	tmpl, err := template.New(name).ParseFS(templateFS, tmplPath, "templates/error_pages.tmpl", "templates/waf.tmpl")
	if err != nil {
		return nil, fmt.Errorf("parsing template %s: %w", name, err)
	}

	var buf bytes.Buffer
	if err := tmpl.Execute(&buf, data); err != nil {
		return nil, fmt.Errorf("executing template %s: %w", name, err)
	}

	return buf.Bytes(), nil
}
