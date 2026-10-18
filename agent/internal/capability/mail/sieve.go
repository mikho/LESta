package mail

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
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

// renderSieveScript builds a's own Sieve script text, in this order:
//
//  1. its block list: mail From a blocked address or domain is discarded;
//  2. spam filing, on a domain with antispam (antispam): unless From is on
//     its allow list, a message whose score is at or above the mailbox's
//     sensitivity threshold is filed into Junk (created when missing), and
//     nothing else happens to it (no forward, no autoreply);
//  3. forwarding and autoreply, as before.
//
// The score comes from the X-Lesta-Spam-Score-Int header Exim's data ACL
// adds to every message it scans (after removing any copy the sender put
// there), never below zero: Sieve's i;ascii-numeric comparator treats a
// value that does not start with a digit as infinity.
//
// An account that needs none of this gets an empty script (Dovecot's own
// default "just deliver to INBOX").
//
// forward_only=true (forward, never keep a local copy) uses a plain
// `redirect`, which implicitly cancels the default keep action per RFC 5228
// s4.2. forward_only=false (forward AND keep) uses `redirect :copy`, which
// does not cancel it.
func renderSieveScript(a Account, antispam bool) string {
	var requires []string
	var b strings.Builder

	sensitivity := a.SpamSensitivity
	if sensitivity == "" {
		sensitivity = "medium"
	}
	threshold, filesSpam := spamThresholds[sensitivity]
	filesSpam = filesSpam && antispam

	blockAddresses, blockDomains := splitSenders(a.SpamBlock)
	allowAddresses, allowDomains := splitSenders(a.SpamAllow)

	if filesSpam {
		requires = append(requires, `"fileinto"`, `"mailbox"`, `"relational"`, `"comparator-i;ascii-numeric"`)
	}
	if a.ForwardTo != nil {
		requires = append(requires, `"copy"`)
	}
	if a.AutoreplyEnabled {
		requires = append(requires, `"vacation"`)
	}

	hasBlock := len(blockAddresses) > 0 || len(blockDomains) > 0

	if len(requires) == 0 && !hasBlock {
		return ""
	}

	if len(requires) > 0 {
		fmt.Fprintf(&b, "require [%s];\n\n", strings.Join(requires, ", "))
	}

	if len(blockAddresses) > 0 {
		fmt.Fprintf(&b, "if address :is :all \"from\" %s {\n    discard;\n    stop;\n}\n", sieveList(blockAddresses))
	}
	if len(blockDomains) > 0 {
		fmt.Fprintf(&b, "if address :is :domain \"from\" %s {\n    discard;\n    stop;\n}\n", sieveList(blockDomains))
	}

	if filesSpam {
		tests := []string{fmt.Sprintf(`header :value "ge" :comparator "i;ascii-numeric" "X-Lesta-Spam-Score-Int" "%d"`, threshold)}
		if len(allowAddresses) > 0 {
			tests = append(tests, fmt.Sprintf(`not address :is :all "from" %s`, sieveList(allowAddresses)))
		}
		if len(allowDomains) > 0 {
			tests = append(tests, fmt.Sprintf(`not address :is :domain "from" %s`, sieveList(allowDomains)))
		}

		fmt.Fprintf(&b, "if allof (%s) {\n    fileinto :create \"Junk\";\n    stop;\n}\n", strings.Join(tests, ", "))
	}

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

// splitSenders separates a validated sender list into addresses and domains.
func splitSenders(entries []string) (addresses, domains []string) {
	for _, entry := range entries {
		if strings.Contains(entry, "@") {
			addresses = append(addresses, entry)
		} else {
			domains = append(domains, entry)
		}
	}

	return addresses, domains
}

// sieveList renders validated entries as a Sieve string list. The entries
// match memberEmailPattern or domainPattern, so none holds a quote or a
// backslash.
func sieveList(entries []string) string {
	quoted := make([]string, len(entries))
	for i, entry := range entries {
		quoted[i] = `"` + entry + `"`
	}

	return "[" + strings.Join(quoted, ", ") + "]"
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

			content := renderSieveScript(a, d.AntispamEnabled)
			finalPath := c.sievePath(d.Domain, a.LocalPart)

			if content == "" {
				_ = os.Remove(finalPath)

				continue
			}

			if err := ensureSieveDomainDir(filepath.Dir(finalPath)); err != nil {
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

// ensureSieveDomainDir creates (or puts right) a domain's Sieve directory,
// mode 0770 with the group of the installer's SIEVE_DIR it sits in (vmail):
// Dovecot delivers as vmail and saves each script's compiled .svbin next to
// it, which a 0755 directory refused, so every delivery compiled the script
// again and logged "Permission denied". The group is set explicitly rather
// than through setgid, since the applier's RestrictSUIDSGID refuses any chmod
// that sets it.
func ensureSieveDomainDir(dir string) error {
	if err := os.MkdirAll(dir, 0o770); err != nil {
		return err
	}

	parent, err := os.Stat(filepath.Dir(dir))
	if err != nil {
		return err
	}

	if stat, ok := parent.Sys().(*syscall.Stat_t); ok {
		if err := os.Chown(dir, -1, int(stat.Gid)); err != nil {
			return err
		}
	}

	return os.Chmod(dir, 0o770)
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
	// sievec saves its compiled output beside the staged script; only the
	// verdict is wanted, and Dovecot compiles the activated script itself.
	_ = os.Remove(path + ".svbin")
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
