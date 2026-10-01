package phpfpm

import (
	"bytes"
	"embed"
	"fmt"
	"path"
	"text/template"
)

//go:embed templates/pool.conf.tmpl
var templateFS embed.FS

// poolData is the substitution set for the pool template. Every field is
// either already-validated payload data or an agent-computed path; tenant
// input never selects a template file (there is only one), it only ever
// fills already-validated placeholders.
type poolData struct {
	ResourceID      string
	AccountUsername string
	SocketPath      string
	Docroot         string
}

// renderPool renders resourceID's own pool fragment. Unlike nginx's own
// suspended-page swap, a suspended domain's traffic is already fully
// blocked at the web-server layer (nginx/apache renders a suspended
// placeholder and never reaches this pool's socket at all), so this
// capability renders the identical pool regardless of Payload.Suspended --
// there is no separate "disabled pool" shape to select.
func renderPool(data poolData) ([]byte, error) {
	tmplPath := path.Join("templates", "pool.conf.tmpl")

	tmpl, err := template.New("pool.conf.tmpl").ParseFS(templateFS, tmplPath)
	if err != nil {
		return nil, fmt.Errorf("parsing pool template: %w", err)
	}

	var buf bytes.Buffer
	if err := tmpl.Execute(&buf, data); err != nil {
		return nil, fmt.Errorf("executing pool template: %w", err)
	}

	return buf.Bytes(), nil
}
