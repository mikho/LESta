package identity

import (
	"context"
	"strings"

	"github.com/mikho/LESta/agent/internal/capability/fragment"
)

// validateCandidate tests a candidate Match-block fragment without disturbing
// the live config: it builds a synthetic full sshd_config that includes every
// other currently-live account's own fragment plus candidatePath (renamed to
// <resourceID>.conf, so sshd's own glob Include picks it up inside the
// scratch directory), then runs `sshd -t -f <synthetic config>`. Mirrors
// nginx.validateCandidate exactly, `Include` being sshd_config's own real
// directive for exactly this purpose, same as nginx's `include`.
//
// candidatePath == "" means "validate this resource's fragment as absent"
// (used by delete): every other live fragment is included, this resource's
// own is not.
//
// A non-nil *ValidationError means sshd rejected the config (its own stderr
// is the message); any other non-nil error means the harness itself
// couldn't run (a "no verdict reached" infrastructure failure, not a
// business rejection).
func (c *IdentityCapability) validateCandidate(ctx context.Context, resourceID string, candidatePath string) error {
	syntheticConfPath, cleanup, err := c.buildSyntheticConfig(resourceID, candidatePath)
	if err != nil {
		return err
	}
	defer cleanup()

	cmd := c.cfg.command(ctx, c.cfg.sshdBinary(), "-t", "-f", syntheticConfPath)

	out, err := cmd.CombinedOutput()
	if err != nil {
		return &ValidationError{
			Code:    "sshd_config_invalid",
			Message: strings.TrimSpace(string(out)),
		}
	}

	return nil
}

// buildSyntheticConfig copies the real (read-only) sshd_config and
// substitutes its own `Include <SftpConfigDir>/*.conf` line for one pointing
// at a scratch directory of every other currently-live account fragment,
// plus the candidate (if any). The returned cleanup func removes the entire
// scratch tree; callers must defer it.
func (c *IdentityCapability) buildSyntheticConfig(resourceID string, candidatePath string) (string, func(), error) {
	return fragment.SyntheticConfig{
		Service:          "identity",
		BaseConfPath:     c.cfg.SshdConfigPath,
		BaseConfName:     "sshd_config",
		Dir:              c.dir(),
		FragmentsDirName: "fragments",
		IncludeKeyword:   "Include",
	}.Build(resourceID, candidatePath)
}
