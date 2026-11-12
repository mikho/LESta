package nginx

import (
	"context"
	"strings"

	"github.com/mikho/LESta/agent/internal/capability/fragment"
)

// validateCandidate tests a candidate fragment without disturbing the live
// config: it builds a synthetic full nginx config that includes every other
// currently-live fragment plus candidatePath (renamed to <resourceID>.conf, so
// nginx's own glob picks it up inside the scratch directory), then runs
// `nginx -t -c <synthetic config>`.
//
// candidatePath == "" means "validate this resource's fragment as absent"
// (used by delete): every other live fragment is included, this resource's own
// is not.
//
// A non-nil *ValidationError means nginx rejected the config (its own stderr is
// the message); any other non-nil error means the harness itself couldn't run
// (a "no verdict reached" infrastructure failure, not a business rejection).
func (c *NginxCapability) validateCandidate(ctx context.Context, resourceID string, candidatePath string) error {
	syntheticConfPath, cleanup, err := c.buildSyntheticConfig(resourceID, candidatePath)
	if err != nil {
		return err
	}
	defer cleanup()

	// NOTE on relative paths in some OTHER, non-LESta-managed site's own
	// config (e.g. a stock `include snippets/fastcgi-php.conf;` inside a
	// hand-authored vhost that coexists in sites-enabled/ on a combined
	// control-plane+node deployment): nginx resolves a relative include
	// against the directory portion of whatever path was given to -c, never
	// against -p (confirmed directly against a real nginx binary -- -p had
	// zero effect here, contrary to what an earlier version of this comment
	// claimed). Since -c always points at a synthetic file under a scratch
	// tmpDir here, any coexisting site with a relative include of its own
	// will fail this validation no matter what flags are added -- there is
	// no fix on this side of the boundary. The real fix is operational: any
	// hand-authored site sharing a node with LESta's own managed fragments
	// must use absolute include paths (documented in the Installation
	// Guide's own Chapter 11 template). Every path LESta itself ever writes
	// into a fragment is already absolute, so this only affects config this
	// capability does not own.
	args := c.cfg.commandArgs("-t", "-c", syntheticConfPath)

	cmd := c.cfg.command(ctx, args...)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return &ValidationError{
			Code:    "nginx_config_invalid",
			Message: strings.TrimSpace(string(out)),
		}
	}

	return nil
}

// buildSyntheticConfig copies the real (read-only) nginx.conf and substitutes
// its `include <LiveDir>/*.conf;` line for one pointing at a scratch directory
// of every other currently-live fragment plus the candidate (if any). The
// returned cleanup func removes the entire scratch tree; callers must defer it.
func (c *NginxCapability) buildSyntheticConfig(resourceID string, candidatePath string) (string, func(), error) {
	return fragment.SyntheticConfig{
		Service:          "nginx",
		BaseConfPath:     c.cfg.NginxConfPath,
		BaseConfName:     "nginx.conf",
		Dir:              c.dir(),
		FragmentsDirName: "fragments",
		IncludeKeyword:   "include",
	}.Build(resourceID, candidatePath)
}
