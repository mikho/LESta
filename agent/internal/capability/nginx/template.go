package nginx

import (
	"bytes"
	"embed"
	"fmt"
	"path"
	"text/template"
)

//go:embed templates/default.conf.tmpl templates/suspended.conf.tmpl
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
	// SuspendedPage is suspendedHTML's content, substituted in only when
	// rendering the suspended template.
	SuspendedPage string
}

func (d vhostData) marker() string {
	return fmt.Sprintf("LESTA-MARKER resource=%s", d.ResourceID)
}

// renderVhost renders the vhost fragment for data. suspended selects between
// the two built-in templates; it is a pure function of the payload
// (payload.Suspended), never of the requested operation's name, so
// create/update/suspend/unsuspend can all funnel through the identical
// rendering call.
func renderVhost(data vhostData, suspended bool) ([]byte, error) {
	data.Marker = data.marker()

	name := "default.conf.tmpl"
	if suspended {
		name = "suspended.conf.tmpl"
		data.SuspendedPage = string(suspendedHTML)
	}

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
