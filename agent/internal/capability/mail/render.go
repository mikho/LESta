package mail

import (
	"context"
	"fmt"
	"os/exec"
	"sort"
	"strings"
)

// lsearchLine builds one "key: value\n" lsearch-format data line. No
// escaping is needed: every value this package ever writes is either a
// domain (domainPattern), a local@domain address, an absolute file path
// this package itself constructs, or a fixed literal "1" marker -- never
// raw tenant free text.
func lsearchLine(key, value string) string {
	return key + ": " + value + "\n"
}

// activeDomains returns the set of Payloads (this apply's own new payload
// for resourceID, plus every sibling from listKnownDomains) in a stable,
// sorted order so re-rendering identical input always produces byte-
// identical output (load-bearing for generation.ComputeDigest's own drift
// detection).
func (c *MailCapability) activeDomains(resourceID string, payload Payload) ([]Payload, error) {
	siblings, err := c.listKnownDomains(resourceID)
	if err != nil {
		return nil, err
	}

	all := append(siblings, payload)

	sort.Slice(all, func(i, j int) bool { return all[i].Domain < all[j].Domain })

	return all, nil
}

// renderedData is every aggregate Exim/Dovecot data file this capability
// maintains, keyed by its own fixed filename under Config.EximDataDir
// (except dovecotPasswd, which lives at Config.DovecotPasswdPath directly:
// it is consumed by a different service's own structural config).
//
// forward_to/forward_only are deliberately NOT rendered as an Exim-level
// lookup at all: unlike domain/account existence (which Exim's own RCPT ACL
// must enforce before ever accepting a message, since rejecting late is the
// whole point of the relay-safety design), per-account forwarding is
// naturally a delivery-time decision, and Dovecot's LMTP delivery already
// runs each account's own Sieve script (see sieve.go) at exactly that
// moment. A Sieve `redirect` action is the standard, idiomatic mechanism for
// this, and using it here avoids a second, redundant Exim-level redirect
// router keyed on the same data.
type renderedData struct {
	domains       string
	accounts      string
	antivirus     string
	antispam      string
	dkimKeys      string
	dkimSelectors string
	catchall      string
	dovecotPasswd string
}

// hashPassword shells out to the real `doveadm pw` binary, this module's
// own zero-dependency policy applied to password hashing exactly like every
// other real-binary invocation in this codebase, rather than vendoring a
// bcrypt implementation. BLF-CRYPT (bcrypt): deliberately chosen over
// SHA512-CRYPT, which some libc crypt(3) implementations (confirmed
// directly: this local macOS/Homebrew Dovecot build) do not support at all,
// unlike BLF-CRYPT, which Dovecot implements itself rather than delegating
// to the host's own crypt(3). Never MD5, per the Mail Threat Model's own
// non-negotiable, correcting the legacy Vesta anti-pattern.
func (c *MailCapability) hashPassword(ctx context.Context, plaintext string) (string, error) {
	cmd := exec.CommandContext(ctx, c.cfg.doveadmBinary(), "pw", "-s", "BLF-CRYPT", "-p", plaintext)

	out, err := cmd.Output()
	if err != nil {
		return "", fmt.Errorf("hashing password: %w", err)
	}

	return strings.TrimSpace(string(out)), nil
}

// passwdFileLine renders one Dovecot passwd-file passdb/userdb line:
// user:password:uid:gid:gecos:home:shell:extra_fields. uid/gid/gecos/home/
// shell are deliberately left blank: every virtual mail user shares the
// same fixed mail_uid/mail_gid/mail_home configured once in Dovecot's own
// structural config (DovecotConfPath), isolated from each other purely by
// their own per-domain/per-account Maildir path (%d/%n expansion), never by
// a distinct real Unix identity -- the standard, well-documented virtual-
// mailbox-hosting pattern. quotaMB becomes a userdb_quota_rule extra field
// (Dovecot's own quota plugin convention); nil means unlimited, so no rule
// is emitted at all.
func passwdFileLine(address, hash string, quotaMB *int) string {
	extra := ""
	if quotaMB != nil {
		extra = fmt.Sprintf("userdb_quota_rule=*:bytes=%dM", *quotaMB)
	}

	return fmt.Sprintf("%s:%s:::::: %s\n", address, hash, extra)
}

// render builds every aggregate data file's full content fresh from domains
// (activeDomains' own output) and ctx (for the real doveadm pw invocation a
// new plaintext password needs). credentials is the durable, suspension-
// independent address -> hash archive (see credentials.go's own doc comment
// for why the rendered passwd file itself cannot be that source of truth):
// it is both read from (for an account whose password this apply's own
// payload doesn't carry) and written into (for one that carries a newly
// created or rotated plaintext), mutated in place so the caller can persist
// it via saveCredentials once this whole render has succeeded.
//
// Suspended domains, and suspended accounts (individually or via their own
// domain's suspension), are simply omitted from every Exim/Dovecot-facing
// file here -- the same "suspended = absent from rendered live config"
// idiom this module already uses uniformly (bind9's stanza omission,
// apache's vhost omission), never a credential-field mutation, per the Mail
// Threat Model's own non-negotiable prohibition. A suspended account's own
// hash is still recorded into credentials if its payload happens to carry a
// new one (never true under today's real ADR-constrained call sites, but
// this function does not assume that), so a later unsuspend still has
// something real to restore.
func (c *MailCapability) render(ctx context.Context, domains []Payload, credentials map[string]string) (renderedData, error) {
	var domainsList, accountsList, antivirusList, antispamList, dkimKeysList, dkimSelectorsList, catchallList, passwdFile strings.Builder

	for _, d := range domains {
		if d.Suspended {
			continue
		}

		domainsList.WriteString(lsearchLine(d.Domain, "1"))

		if d.AntivirusEnabled {
			antivirusList.WriteString(lsearchLine(d.Domain, "1"))
		}

		if d.AntispamEnabled {
			antispamList.WriteString(lsearchLine(d.Domain, "1"))
		}

		if d.DkimEnabled && c.dkimKeyExists(d.Domain, d.DkimActiveSelector) {
			dkimKeysList.WriteString(lsearchLine(d.Domain, c.dkimPrivateKeyPath(d.Domain, d.DkimActiveSelector)))
			dkimSelectorsList.WriteString(lsearchLine(d.Domain, d.DkimActiveSelector))
		}

		if d.CatchallEmail != nil {
			catchallList.WriteString(lsearchLine(d.Domain, *d.CatchallEmail))
		}

		for _, a := range d.Accounts {
			address := a.LocalPart + "@" + d.Domain

			if a.Password != nil {
				hash, err := c.hashPassword(ctx, *a.Password)
				if err != nil {
					return renderedData{}, fmt.Errorf("hashing password for %s: %w", address, err)
				}

				credentials[address] = hash
			}

			if a.Suspended {
				continue
			}

			accountsList.WriteString(lsearchLine(address, "1"))

			hash, ok := credentials[address]
			if !ok || hash == "" {
				return renderedData{}, fmt.Errorf("account %s has no password on record at all (neither a new one in this payload nor an existing hash) -- a create or rotate operation must always carry one", address)
			}

			passwdFile.WriteString(passwdFileLine(address, hash, a.QuotaMB))
		}
	}

	return renderedData{
		domains:       domainsList.String(),
		accounts:      accountsList.String(),
		antivirus:     antivirusList.String(),
		antispam:      antispamList.String(),
		dkimKeys:      dkimKeysList.String(),
		dkimSelectors: dkimSelectorsList.String(),
		catchall:      catchallList.String(),
		dovecotPasswd: passwdFile.String(),
	}, nil
}
