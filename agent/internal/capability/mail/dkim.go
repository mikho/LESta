package mail

import (
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

// Real selector rotation (a second selector, retained until DNS TTL-bounded
// propagation is certain, per the Mail Threat Model's own "DKIM rotation"
// gate item): MailDomain.dkim_selector/dkim_pending_selector/
// dkim_retiring_selector on the Laravel side track which selector is
// currently active for signing, which (if any) is a newly generated
// candidate whose DNS record is being propagated before signing switches to
// it, and which (if any) is being retired after signing has already moved
// off it. This package stores one real keypair per (domain, selector) pair
// -- selector is part of the filename, not the directory -- so several
// selectors can coexist on disk for the same domain during a rotation
// window; see Payload's own doc comment for exactly which selector(s) a
// given apply is asked to ensure, sign with, or retire.
func (c *MailCapability) dkimKeyDir(domain string) string {
	return filepath.Join(c.cfg.DKIMKeyRoot, domain)
}

func (c *MailCapability) dkimPrivateKeyPath(domain, selector string) string {
	return filepath.Join(c.dkimKeyDir(domain), selector+".private")
}

func (c *MailCapability) dkimPublicKeyPath(domain, selector string) string {
	return filepath.Join(c.dkimKeyDir(domain), selector+".public")
}

// ensureDKIMKey generates a real RSA keypair for domain if dkim is enabled
// and no key exists yet. The private key is written mode 0640, owned
// lesta-agent:lesta (the daemon's own real identity and primary group --
// see .install/services/agent-daemon/install.sh), under DKIMKeyRoot and
// NEVER read back into this process's own return values, any generation
// meta, or any ResultEnvelope field -- per the Mail Threat Model's own
// non-negotiable prohibition, it must never leave this node. It is not
// mode 0600: real signing happens inside the Exim daemon process (a
// different system identity, Debian-exim on Debian/Ubuntu), which the
// mail installer adds to the lesta group specifically so it can read this
// file -- 0600 would make every real signing attempt fail outright with a
// permission error, discovered while building that installer.
//
// The derived PUBLIC key is written alongside it (mode 0644: it is not a
// secret) and, unlike the private key, IS read back out by
// dkimResultData/dkimPublicKeyBase64 below and reported on a successful
// apply's own ResultEnvelope.Data, letting Laravel publish the matching DNS
// TXT record without this capability needing any DNS-specific knowledge of
// its own (see PublishesDkimDnsRecord.php on the Laravel side).
//
// Disabling dkim_enabled later does not delete existing key material: it is
// simply omitted from the active signing lookup (see render.go), the same
// "suspended/disabled = absent from rendered live config" idiom every other
// capability in this module already uses.
func (c *MailCapability) ensureDKIMKey(domain, selector string, enabled bool) error {
	if !enabled {
		return nil
	}

	if _, err := os.Stat(c.dkimPrivateKeyPath(domain, selector)); err == nil {
		return nil
	} else if !os.IsNotExist(err) {
		return fmt.Errorf("checking for existing DKIM key for %s/%s: %w", domain, selector, err)
	}

	if err := os.MkdirAll(c.dkimKeyDir(domain), 0o750); err != nil {
		return fmt.Errorf("creating DKIM key directory for %s: %w", domain, err)
	}

	privPath := c.dkimPrivateKeyPath(domain, selector)

	genCmd := exec.Command(c.cfg.opensslBinary(), "genrsa", "-out", privPath, "2048")
	if out, err := genCmd.CombinedOutput(); err != nil {
		return fmt.Errorf("generating DKIM private key for %s/%s: %w: %s", domain, selector, err, string(out))
	}

	if err := os.Chmod(privPath, 0o640); err != nil {
		return fmt.Errorf("setting DKIM private key permissions for %s/%s: %w", domain, selector, err)
	}

	pubPath := c.dkimPublicKeyPath(domain, selector)

	pubCmd := exec.Command(c.cfg.opensslBinary(), "rsa", "-in", privPath, "-pubout", "-out", pubPath)
	if out, err := pubCmd.CombinedOutput(); err != nil {
		return fmt.Errorf("deriving DKIM public key for %s/%s: %w: %s", domain, selector, err, string(out))
	}

	return nil
}

// retireDKIMKey deletes a retired selector's real key material from this
// node: a one-shot cleanup Laravel triggers only once its own DNS record for
// this selector is already gone and its rotation bookkeeping has already
// moved on (see Payload.DkimRetireSelector's own doc comment). Missing files
// are not an error -- idempotent, safe to report the same retirement twice.
func (c *MailCapability) retireDKIMKey(domain, selector string) error {
	if err := os.Remove(c.dkimPrivateKeyPath(domain, selector)); err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("removing retired DKIM private key for %s/%s: %w", domain, selector, err)
	}

	if err := os.Remove(c.dkimPublicKeyPath(domain, selector)); err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("removing retired DKIM public key for %s/%s: %w", domain, selector, err)
	}

	return nil
}

// dkimKeyExists reports whether domain/selector has a real generated DKIM
// key on this node, used by render.go to decide whether to include the
// active selector in the signing lookup (never assume a key exists just
// because dkim_enabled is true: ensureDKIMKey must have actually run and
// succeeded first) and by dkimResultData below for the same reason.
func (c *MailCapability) dkimKeyExists(domain, selector string) bool {
	_, err := os.Stat(c.dkimPrivateKeyPath(domain, selector))

	return err == nil
}

// dkimSelectorKeyPayload is one selector's real, reportable public key.
type dkimSelectorKeyPayload struct {
	Selector  string `json:"selector"`
	PublicKey string `json:"public_key"`
}

// dkimResultPayload is the shape reported on a successful apply's own
// ResultEnvelope.Data whenever DKIM is actively enabled, matching
// PublishesDkimDnsRecord.php's own expected keys exactly (mirroring
// backup.artifactData's own "Go struct documents the Laravel-side
// consumer" precedent). Pending is only ever present mid-rotation, while a
// second selector's own DNS record is being propagated before Laravel
// switches signing over to it.
type dkimResultPayload struct {
	Active  dkimSelectorKeyPayload  `json:"active"`
	Pending *dkimSelectorKeyPayload `json:"pending,omitempty"`
}

// dkimResultData returns the marshaled {active: {selector, public_key},
// pending?: {...}} to attach to ResultEnvelope.Data for domain, or nil
// (never an error) when DKIM isn't actively enabled or the active
// selector's key hasn't actually been generated yet: a disabled or
// not-yet-real key means nothing to report, not a zero-value placeholder.
// pendingSelector is only ever included if its own key already exists too
// (the same "never assume, always check" discipline as the active one).
func (c *MailCapability) dkimResultData(domain, activeSelector string, pendingSelector *string, active bool) (json.RawMessage, error) {
	if !active || !c.dkimKeyExists(domain, activeSelector) {
		return nil, nil
	}

	activePublicKey, err := c.dkimPublicKeyBase64(domain, activeSelector)
	if err != nil {
		return nil, fmt.Errorf("reading active DKIM public key for %s/%s: %w", domain, activeSelector, err)
	}

	payload := dkimResultPayload{Active: dkimSelectorKeyPayload{Selector: activeSelector, PublicKey: activePublicKey}}

	if pendingSelector != nil && c.dkimKeyExists(domain, *pendingSelector) {
		pendingPublicKey, err := c.dkimPublicKeyBase64(domain, *pendingSelector)
		if err != nil {
			return nil, fmt.Errorf("reading pending DKIM public key for %s/%s: %w", domain, *pendingSelector, err)
		}

		payload.Pending = &dkimSelectorKeyPayload{Selector: *pendingSelector, PublicKey: pendingPublicKey}
	}

	data, err := json.Marshal(payload)
	if err != nil {
		return nil, fmt.Errorf("marshaling DKIM result data for %s: %w", domain, err)
	}

	return data, nil
}

// dkimPublicKeyBase64 reads domain/selector's own generated PEM public key
// file and returns just its base64 body, with the PEM header/footer lines
// and every embedded newline stripped: the exact single-line form a DKIM
// TXT record's own "p=" field requires (RFC 6376 section 3.6.1), which
// openssl's own PEM output never produces directly.
func (c *MailCapability) dkimPublicKeyBase64(domain, selector string) (string, error) {
	pemBytes, err := os.ReadFile(c.dkimPublicKeyPath(domain, selector))
	if err != nil {
		return "", err
	}

	var b strings.Builder

	for _, line := range strings.Split(string(pemBytes), "\n") {
		line = strings.TrimSpace(line)
		if line == "" || strings.HasPrefix(line, "-----") {
			continue
		}

		b.WriteString(line)
	}

	return b.String(), nil
}
