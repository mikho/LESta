package identity

import (
	"bytes"
	"embed"
	"fmt"
	"text/template"
)

//go:embed templates/match.conf.tmpl
var templateFS embed.FS

// matchData is the substitution set for templates/match.conf.tmpl. Every
// field is already-validated payload data or an agent-computed path; tenant
// input never selects a template file, it only ever fills placeholders.
type matchData struct {
	Username   string
	ChrootRoot string
}

// renderMatchBlock renders this account's own `Match User` sshd_config
// fragment. There is no "suspended" branch the way nginx/apache/bind9 have:
// an account with no ssh_public_key on file already cannot authenticate
// (renderAuthorizedKeys below renders an empty file for it), so the Match
// block itself is identical whether or not a key exists -- rendering it
// unconditionally is simpler and, per this project's own established
// discipline, means create/update/suspend/unsuspend never need a content
// branch of their own here at all.
func renderMatchBlock(data matchData) ([]byte, error) {
	tmpl, err := template.New("match.conf.tmpl").ParseFS(templateFS, "templates/match.conf.tmpl")
	if err != nil {
		return nil, fmt.Errorf("parsing template match.conf.tmpl: %w", err)
	}

	var buf bytes.Buffer
	if err := tmpl.Execute(&buf, data); err != nil {
		return nil, fmt.Errorf("executing template match.conf.tmpl: %w", err)
	}

	return buf.Bytes(), nil
}

// renderAuthorizedKeys renders the account's own single-line
// authorized_keys file content: sshPublicKey verbatim plus a trailing
// newline, or an empty file when sshPublicKey is nil/empty -- an empty
// file is a real, valid authorized_keys file that simply authorizes no
// key, the same "render unconditionally, absence is just empty content"
// discipline renderMatchBlock above documents.
func renderAuthorizedKeys(sshPublicKey *string) []byte {
	if sshPublicKey == nil || *sshPublicKey == "" {
		return []byte{}
	}

	return []byte(*sshPublicKey + "\n")
}
