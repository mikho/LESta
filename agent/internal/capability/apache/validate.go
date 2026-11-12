package apache

import (
	"context"
	"os/exec"
	"strings"

	"github.com/mikho/LESta/agent/internal/capability/fragment"
)

// validateCandidate tests a candidate fragment without disturbing the live
// config: it builds a synthetic full apache2 config that includes every other
// currently-live fragment plus candidatePath (renamed to <resourceID>.conf, so
// apache2's own glob picks it up inside the scratch directory), then runs
// `apache2 -t -f <synthetic config>`.
//
// candidatePath == "" means "validate this resource's fragment as absent"
// (used by delete): every other live fragment is included, this resource's own
// is not.
//
// A non-nil *ValidationError means apache2 rejected the config (its own
// stderr is the message); any other non-nil error means the harness itself
// couldn't run (a "no verdict reached" infrastructure failure, not a business
// rejection).
func (c *ApacheCapability) validateCandidate(ctx context.Context, resourceID string, candidatePath string) error {
	syntheticConfPath, cleanup, err := c.buildSyntheticConfig(resourceID, candidatePath)
	if err != nil {
		return err
	}
	defer cleanup()

	args := c.cfg.commandArgs("-t", "-f", syntheticConfPath)

	cmd := exec.CommandContext(ctx, c.cfg.apacheBinary(), args...)
	cmd.Env = c.cfg.Env

	out, err := cmd.CombinedOutput()
	if err != nil {
		return &ValidationError{
			Code:    "apache_config_invalid",
			Message: strings.TrimSpace(string(out)),
		}
	}

	return nil
}

// buildSyntheticConfig copies the real (read-only) apache2.conf and
// substitutes its `IncludeOptional <LiveDir>/*.conf` line (however the real
// file spells that directive -- Include or IncludeOptional) for one pointing
// at a scratch directory of every other currently-live fragment plus the
// candidate (if any). The substituted line is always rewritten as
// IncludeOptional, regardless of the original directive's own spelling: a
// synthetic directory with zero fragments (e.g. validating a delete that
// removes the last resource) must never hard-fail the way a plain Include does
// when its glob matches nothing (verified directly: `apache2 -t` errors "No
// matches for the wildcard ... failing" for a bare Include, but reports Syntax
// OK for IncludeOptional against the same empty directory). The returned
// cleanup func removes the entire scratch tree; callers must defer it.
func (c *ApacheCapability) buildSyntheticConfig(resourceID string, candidatePath string) (string, func(), error) {
	return fragment.SyntheticConfig{
		Service:          "apache",
		BaseConfPath:     c.cfg.ApacheConfPath,
		BaseConfName:     "apache2.conf",
		Dir:              c.dir(),
		FragmentsDirName: "fragments",
		IncludeKeyword:   "Include",
		RewriteInclude: func(_, _, fragGlob string) string {
			return "IncludeOptional " + fragGlob
		},
	}.Build(resourceID, candidatePath)
}
