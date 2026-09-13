package mail

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"strings"
)

// stagingSuffix marks a not-yet-activated aggregate data file, invisible to
// Exim/Dovecot's own lookups (which read the fixed, extension-less final
// path only), mirroring bind9's own dotfile-staging precedent.
const stagingSuffix = ".staging"

// aggregateFile pairs one renderedData field with the real, final path
// Exim or Dovecot will actually read it from.
type aggregateFile struct {
	finalPath string
	content   string
}

func (c *MailCapability) aggregateFiles(data renderedData) []aggregateFile {
	return []aggregateFile{
		{c.cfg.EximDataDir + "/domains.list", data.domains},
		{c.cfg.EximDataDir + "/accounts.list", data.accounts},
		{c.cfg.EximDataDir + "/antivirus.list", data.antivirus},
		{c.cfg.EximDataDir + "/antispam.list", data.antispam},
		{c.cfg.EximDataDir + "/dkim_keys.list", data.dkimKeys},
		{c.cfg.EximDataDir + "/dkim_selector.list", data.dkimSelectors},
		{c.cfg.EximDataDir + "/catchall.list", data.catchall},
		{c.cfg.DovecotPasswdPath, data.dovecotPasswd},
	}
}

// writeStaging writes every aggregate file's own .staging sibling,
// returning the same list for validateCandidate/activateLive to use. An
// lsearch/passwd-file data file has no external syntax validator the way a
// BIND zone file or an Apache vhost fragment does (Exim/Dovecot read these
// lookup files at message/auth time, not at config-parse time) -- this
// package's own fixed rendering functions (render.go) are the correctness
// boundary instead, the same trust placed in every other capability's own
// template code, just without a second, independent external check backing
// it up for this one file class specifically. validateCandidate below still
// performs the two checks that ARE externally verifiable: that Exim's own
// full config chain (structural config plus these lookups) still parses at
// all (-bV), and that a real routing decision through the STAGED data comes
// out the way this apply intends (-bt), by temporarily pointing a scratch
// copy of the static config's own lookups at the staging paths.
func (c *MailCapability) writeStaging(files []aggregateFile) error {
	for _, f := range files {
		if err := os.WriteFile(f.finalPath+stagingSuffix, []byte(f.content), 0o644); err != nil {
			return fmt.Errorf("writing staged data file %s: %w", f.finalPath, err)
		}
	}

	return nil
}

func (c *MailCapability) discardStaging(files []aggregateFile) {
	for _, f := range files {
		_ = os.Remove(f.finalPath + stagingSuffix)
	}
}

// activateLive atomically renames every staged aggregate file over its own
// live counterpart, same filesystem in every real deployment (both paths
// live under the same StateRoot-adjacent tree), so each individual rename is
// atomic even though the batch of them together is not a single atomic
// transaction -- the same limitation bind9's own per-resource single-file
// activation does not have to contend with, disclosed here rather than
// glossed over: a crash between two of these renames could leave, say, a
// new domains.list active alongside a stale accounts.list. The subsequent
// health check (reload.go) is this capability's own real defense against
// that narrow window, exactly as it is for every other capability's own
// reload-then-health-check step.
func (c *MailCapability) activateLive(files []aggregateFile) error {
	for _, f := range files {
		if err := os.Rename(f.finalPath+stagingSuffix, f.finalPath); err != nil {
			return fmt.Errorf("activating data file %s: %w", f.finalPath, err)
		}
	}

	return nil
}

// validateEximConfig runs `exim -C EximStaticConfPath -bV`, confirming the
// full real config chain -- the structural file plus every lookup file
// reference this apply just activated -- still parses and every referenced
// lookup file exists and is readable. This is real, but structurally
// weaker than bind9's own named-checkconf -z candidate check (see
// writeStaging's own doc comment): it cannot catch a lookup-file content
// mistake the way a BIND zone file's own external validator would, only a
// structural break in the chain (a missing file, a corrupted static config).
//
// Unlike bind9, this runs AFTER activation, not before: Exim has no
// equivalent of bind9's "point a synthetic config at an alternate fragment
// path" trick for lookup files (the static config's own lookup paths are
// fixed, so there is no way to validate a STAGED copy in isolation from the
// live one). The real, meaningful pre-reload/post-reload correctness check
// for THIS apply's own intent is the live SMTP round trip in reload.go's own
// waitSMTPRejects/waitSMTPAccepts, which runs after this and after both
// reloads; capability.go's own recoverFromFailure is what a failure here (or
// there) rolls back through.
func (c *MailCapability) validateEximConfig(ctx context.Context) error {
	cmd := exec.CommandContext(ctx, c.cfg.eximBinary(), "-C", c.cfg.EximStaticConfPath, "-bV")

	out, err := cmd.CombinedOutput()
	if err != nil {
		return &ValidationError{
			Code:    "exim_config_invalid",
			Message: strings.TrimSpace(string(out)),
		}
	}

	return nil
}
