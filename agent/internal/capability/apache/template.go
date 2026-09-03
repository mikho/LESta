package apache

import (
	"bytes"
	"embed"
	"fmt"
	"path"
	"text/template"
)

//go:embed templates/default.conf.tmpl templates/suspended.conf.tmpl templates/default_ssl.conf.tmpl templates/default.asis.tmpl templates/suspended.asis.tmpl
var templateFS embed.FS

// suspendedHTML is the static maintenance page served for every suspended
// resource. Its content is identical for all resources (it carries no
// per-resource data), so it needs no directory of its own on disk: it is
// substituted directly into suspended.asis.tmpl's rendered body at render time.
//
//go:embed templates/suspended.html
var suspendedHTML []byte

// vhostData is the substitution set for every template this package renders
// (both the LiveDir vhost fragment and its companion mod_asis content file).
// Every field is either already hostname-validated payload data or an
// agent-computed value; tenant input never selects which template *file* gets
// parsed (that's a plain Go switch in renderVhost/renderContent, below), it
// only ever fills already-validated placeholders.
type vhostData struct {
	ResourceID string
	Domain     string
	Aliases    []string
	IPAddress  string
	Port       int
	// ContentDir is the absolute path to this generation's own content
	// directory (StateRoot/domains/<resource_id>/generations/<n>/), which the
	// rendered vhost fragment's DocumentRoot points at and whose
	// DirectoryIndex+SetHandler pair hands every request to mod_asis's
	// "content" file within it (see content.go's contentPath/writeContent).
	ContentDir string
	// AcmeChallengeDir backs every template's shared acme-challenge Alias
	// block (see Config's own field of the same name).
	AcmeChallengeDir string
	// CertificatePath and PrivateKeyPath, both non-empty, select
	// default_ssl.conf.tmpl over default.conf.tmpl (see renderVhost) and
	// back its second VirtualHost block's SSLCertificateFile/
	// SSLCertificateKeyFile directives. Empty for every domain with no
	// certificate issued yet.
	CertificatePath string
	PrivateKeyPath  string
	// SSLPort backs default_ssl.conf.tmpl's second VirtualHost block's
	// address (see Config's own field of the same name). Unused by every
	// other template.
	SSLPort int
	// Marker is a known string embedded in the rendered default content file's
	// body, so a health check can assert that *this* resource answered, not
	// just that some apache2 vhost is alive. It is deliberately a function of
	// ResourceID alone, never of the generation number: rendering is only pure
	// with respect to the desired state (create then suspend then unsuspend
	// must produce the same served content as create's own) if nothing
	// generation-specific leaks into the rendered bytes. Host-header routing
	// already guarantees a request lands on the right resource's vhost; the
	// marker only needs to confirm that, not which generation rendered it.
	Marker string
	// SuspendedPage is suspendedHTML's content, substituted in only when
	// rendering the suspended content template.
	SuspendedPage string
}

func (d vhostData) marker() string {
	return fmt.Sprintf("LESTA-MARKER resource=%s", d.ResourceID)
}

// prepare fills in Marker and (when suspended) SuspendedPage identically for
// both the vhost fragment and its companion content file, so a single
// applyGeneration call renders a consistent pair from one vhostData value.
func (d vhostData) prepare(suspended bool) vhostData {
	d.Marker = d.marker()

	if suspended {
		d.SuspendedPage = string(suspendedHTML)
	}

	return d
}

func renderTemplate(name string, data vhostData) ([]byte, error) {
	tmplPath := path.Join("templates", name)

	tmpl, err := template.New(name).ParseFS(templateFS, tmplPath)
	if err != nil {
		return nil, fmt.Errorf("parsing template %s: %w", name, err)
	}

	var buf bytes.Buffer
	if err := tmpl.Execute(&buf, data); err != nil {
		return nil, fmt.Errorf("executing template %s: %w", name, err)
	}

	return buf.Bytes(), nil
}

// renderVhost renders the LiveDir VirtualHost fragment for data. suspended
// takes priority and always wins when true; otherwise a non-empty
// CertificatePath selects the SSL-capable template over the plain default.
// Both selectors are pure functions of the payload (payload.Suspended,
// payload.SSL.CertificatePath), never of the requested operation's name, so
// create/update/suspend/unsuspend can all funnel through the identical
// rendering call.
func renderVhost(data vhostData, suspended bool) ([]byte, error) {
	data = data.prepare(suspended)

	name := "default.conf.tmpl"

	switch {
	case suspended:
		name = "suspended.conf.tmpl"
	case data.CertificatePath != "":
		name = "default_ssl.conf.tmpl"
	}

	return renderTemplate(name, data)
}

// renderContent renders the per-generation mod_asis content file for data
// (written by writeContent in content.go), using mod_asis's pseudo-header
// convention: a Status line, a Content-Type line, a blank line, then the
// response body. suspended selects the same template pair as renderVhost,
// kept in lockstep by construction (both are always called with the same
// (data, suspended) pair from applyGeneration).
func renderContent(data vhostData, suspended bool) ([]byte, error) {
	data = data.prepare(suspended)

	name := "default.asis.tmpl"
	if suspended {
		name = "suspended.asis.tmpl"
	}

	return renderTemplate(name, data)
}
