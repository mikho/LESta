package mail

import (
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
)

// dkimSelector is fixed, never tenant input: this pass supports one active
// selector per domain, generated once on first enable. Real rotation (a
// second selector, retained until DNS TTL-bounded propagation is certain,
// per the Mail Threat Model's own "DKIM rotation" gate item) needs a
// dedicated Laravel-side trigger this payload contract does not carry yet;
// tracked as a disclosed gap, not silently reinvented here.
const dkimSelector = "lesta1"

func (c *MailCapability) dkimKeyDir(domain string) string {
	return filepath.Join(c.cfg.DKIMKeyRoot, domain)
}

func (c *MailCapability) dkimPrivateKeyPath(domain string) string {
	return filepath.Join(c.dkimKeyDir(domain), dkimSelector+".private")
}

func (c *MailCapability) dkimPublicKeyPath(domain string) string {
	return filepath.Join(c.dkimKeyDir(domain), dkimSelector+".public")
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
// secret) so an operator or a future control-plane mechanism can read it to
// publish the corresponding DNS TXT record, but this capability has no
// channel of its own to report it back through today: protocol.ResultEnvelope
// carries no generic "derived output" field (confirmed directly against
// agent/internal/protocol/envelope.go), unlike, say, an issued ACME
// certificate's expiry, which Laravel already knows first-hand because it
// issued the certificate itself rather than asking the agent to report it
// back. Wiring an automatic DNS TXT publication is real future work needing
// its own protocol extension, disclosed here rather than silently
// half-implemented.
//
// Disabling dkim_enabled later does not delete existing key material: it is
// simply omitted from the active signing lookup (see render.go), the same
// "suspended/disabled = absent from rendered live config" idiom every other
// capability in this module already uses.
func (c *MailCapability) ensureDKIMKey(domain string, enabled bool) error {
	if !enabled {
		return nil
	}

	if _, err := os.Stat(c.dkimPrivateKeyPath(domain)); err == nil {
		return nil
	} else if !os.IsNotExist(err) {
		return fmt.Errorf("checking for existing DKIM key for %s: %w", domain, err)
	}

	if err := os.MkdirAll(c.dkimKeyDir(domain), 0o750); err != nil {
		return fmt.Errorf("creating DKIM key directory for %s: %w", domain, err)
	}

	privPath := c.dkimPrivateKeyPath(domain)

	genCmd := exec.Command(c.cfg.opensslBinary(), "genrsa", "-out", privPath, "2048")
	if out, err := genCmd.CombinedOutput(); err != nil {
		return fmt.Errorf("generating DKIM private key for %s: %w: %s", domain, err, string(out))
	}

	if err := os.Chmod(privPath, 0o640); err != nil {
		return fmt.Errorf("setting DKIM private key permissions for %s: %w", domain, err)
	}

	pubPath := c.dkimPublicKeyPath(domain)

	pubCmd := exec.Command(c.cfg.opensslBinary(), "rsa", "-in", privPath, "-pubout", "-out", pubPath)
	if out, err := pubCmd.CombinedOutput(); err != nil {
		return fmt.Errorf("deriving DKIM public key for %s: %w: %s", domain, err, string(out))
	}

	return nil
}

// dkimKeyExists reports whether domain has a real generated DKIM key on
// this node, used by render.go to decide whether to include it in the
// active signing lookup (never assume a key exists just because
// dkim_enabled is true: ensureDKIMKey must have actually run and succeeded
// first).
func (c *MailCapability) dkimKeyExists(domain string) bool {
	_, err := os.Stat(c.dkimPrivateKeyPath(domain))

	return err == nil
}
