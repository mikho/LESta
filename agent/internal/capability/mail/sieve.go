package mail

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

// sievePath is address's own rendered Sieve script location, one file per
// account -- unlike the aggregated Exim/Dovecot lookup data (render.go),
// this genuinely is a one-resource-per-file layout, since Dovecot's own
// sieve plugin is configured (in the static DovecotConfPath) to look up
// exactly one script per user by path expansion (sieve = SieveDir/%d/%n.sieve),
// needing no aggregation at all.
func (c *MailCapability) sievePath(domain, localPart string) string {
	return filepath.Join(c.cfg.SieveDir, domain, localPart+".sieve")
}

// renderSieveScript builds a's own Sieve script text. Forwarding and
// autoreply are the only two behaviors this pass renders (see render.go's
// own doc comment for why forwarding lives here rather than as an Exim-
// level lookup); an account with neither enabled gets an empty script
// (equivalent to Dovecot's own default "just deliver to INBOX" behavior,
// but written out explicitly so this function's own output is never empty
// for an account that DOES need one, keeping the two cases structurally
// distinct rather than relying on file absence to mean "no special
// handling").
//
// forward_only=true (forward, never keep a local copy) uses a plain
// `redirect`, which implicitly cancels the default keep action per RFC 5228
// s4.2. forward_only=false (forward AND keep) uses `redirect :copy`, which
// does not cancel it.
func renderSieveScript(a Account) string {
	var requires []string
	var b strings.Builder

	if a.ForwardTo != nil {
		requires = append(requires, `"copy"`)
	}
	if a.AutoreplyEnabled {
		requires = append(requires, `"vacation"`)
	}

	if len(requires) == 0 {
		return ""
	}

	fmt.Fprintf(&b, "require [%s];\n\n", strings.Join(requires, ", "))

	if a.ForwardTo != nil {
		if a.ForwardOnly {
			fmt.Fprintf(&b, "redirect %q;\n", *a.ForwardTo)
		} else {
			fmt.Fprintf(&b, "redirect :copy %q;\n", *a.ForwardTo)
		}
	}

	if a.AutoreplyEnabled {
		message := ""
		if a.AutoreplyMessage != nil {
			message = *a.AutoreplyMessage
		}

		fmt.Fprintf(&b, "vacation :days 1 %q;\n", message)
	}

	return b.String()
}

// writeSieveScripts renders and validates (via the real `sievec` compiler)
// every active account's own Sieve script across domains, staging each one
// as a .staging sibling before any is activated: a single malformed script
// (which should be unreachable given renderSieveScript's own fixed template
// shape and validated inputs, but defense in depth costs little here) must
// never leave some accounts' scripts updated and others not. Returns the
// list of staged (finalPath, stagingPath) pairs for activate.go to
// atomically rename, and removes stale scripts for accounts that no longer
// exist or no longer need one.
func (c *MailCapability) writeSieveScripts(ctx context.Context, domains []Payload) ([][2]string, error) {
	var staged [][2]string

	for _, d := range domains {
		if d.Suspended {
			continue
		}

		for _, a := range d.Accounts {
			if a.Suspended {
				continue
			}

			content := renderSieveScript(a)
			finalPath := c.sievePath(d.Domain, a.LocalPart)

			if content == "" {
				_ = os.Remove(finalPath)

				continue
			}

			if err := os.MkdirAll(filepath.Dir(finalPath), 0o755); err != nil {
				return nil, fmt.Errorf("creating sieve directory for %s@%s: %w", a.LocalPart, d.Domain, err)
			}

			stagingPath := finalPath + ".staging"
			if err := os.WriteFile(stagingPath, []byte(content), 0o644); err != nil {
				return nil, fmt.Errorf("writing staged sieve script for %s@%s: %w", a.LocalPart, d.Domain, err)
			}

			if err := c.validateSieveScript(ctx, stagingPath); err != nil {
				_ = os.Remove(stagingPath)

				return nil, err
			}

			staged = append(staged, [2]string{finalPath, stagingPath})
		}
	}

	return staged, nil
}

// validateSieveScript runs the real Pigeonhole `sievec` compiler against
// path, proven directly (see this package's own harness_test.go) to reject
// a genuinely malformed script (e.g. a missing semicolon or block) with a
// non-zero exit and a real parse-error message, and to accept a
// well-formed one with exit 0. sievec needs a minimal, mail_uid/mail_gid-
// resolvable config context to run at all (confirmed directly: it fails
// closed with "Unknown UNIX UID user" against a config naming a nonexistent
// service account otherwise) -- Config.DovecotConfPath is reused for this,
// since the real structural config already names a real, resolvable mail
// service identity.
func (c *MailCapability) validateSieveScript(ctx context.Context, path string) error {
	args := []string{path}
	if c.cfg.DovecotConfPath != "" {
		args = append([]string{"-c", c.cfg.DovecotConfPath}, args...)
	}

	cmd := exec.CommandContext(ctx, c.cfg.sievecBinary(), args...)

	out, err := cmd.CombinedOutput()
	if err != nil {
		return &ValidationError{
			Code:    "sieve_script_invalid",
			Message: strings.TrimSpace(string(out)),
		}
	}

	return nil
}

// activateSieveScripts atomically renames every staged sieve script into
// place. Called only after every script in the batch has already validated
// successfully (writeSieveScripts's own all-or-nothing discipline).
func activateSieveScripts(staged [][2]string) error {
	for _, pair := range staged {
		if err := os.Rename(pair[1], pair[0]); err != nil {
			return fmt.Errorf("activating sieve script %s: %w", pair[0], err)
		}
	}

	return nil
}

// discardSieveScripts removes every staged (not yet activated) sieve script
// after a validation failure elsewhere in the same apply (e.g. the Exim or
// Dovecot data files themselves failed to validate): a partially-staged
// batch must never leave stray .staging files behind.
func discardSieveScripts(staged [][2]string) {
	for _, pair := range staged {
		_ = os.Remove(pair[1])
	}
}
