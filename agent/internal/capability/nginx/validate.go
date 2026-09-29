package nginx

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
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
// its `include <LiveDir>/*.conf;` line for one pointing at a fresh scratch
// directory populated with symlinks to every other currently-live fragment,
// plus the candidate (if any). The returned cleanup func removes the entire
// scratch tree; callers must defer it.
func (c *NginxCapability) buildSyntheticConfig(resourceID string, candidatePath string) (string, func(), error) {
	tmpDir, err := os.MkdirTemp("", "lesta-nginx-validate-")
	if err != nil {
		return "", nil, fmt.Errorf("creating validation scratch directory: %w", err)
	}

	cleanup := func() { _ = os.RemoveAll(tmpDir) }

	fragDir := filepath.Join(tmpDir, "fragments")
	if err := os.Mkdir(fragDir, 0o755); err != nil {
		cleanup()

		return "", nil, fmt.Errorf("creating fragments scratch directory: %w", err)
	}

	excludeName := resourceID + ".conf"

	existing, err := filepath.Glob(filepath.Join(c.cfg.LiveDir, "*.conf"))
	if err != nil {
		cleanup()

		return "", nil, fmt.Errorf("listing live fragments in %s: %w", c.cfg.LiveDir, err)
	}

	for _, f := range existing {
		if filepath.Base(f) == excludeName {
			continue
		}

		if err := os.Symlink(f, filepath.Join(fragDir, filepath.Base(f))); err != nil {
			cleanup()

			return "", nil, fmt.Errorf("symlinking live fragment %s: %w", f, err)
		}
	}

	if candidatePath != "" {
		if err := os.Symlink(candidatePath, filepath.Join(fragDir, excludeName)); err != nil {
			cleanup()

			return "", nil, fmt.Errorf("symlinking candidate fragment %s: %w", candidatePath, err)
		}
	}

	baseConf, err := os.ReadFile(c.cfg.NginxConfPath)
	if err != nil {
		cleanup()

		return "", nil, fmt.Errorf("reading base nginx.conf %s: %w", c.cfg.NginxConfPath, err)
	}

	liveGlob := filepath.Join(c.cfg.LiveDir, "*.conf")
	fragGlob := filepath.Join(fragDir, "*.conf")

	lines := strings.Split(string(baseConf), "\n")
	replaced := false

	for i, line := range lines {
		if strings.Contains(line, "include") && strings.Contains(line, liveGlob) {
			lines[i] = strings.Replace(line, liveGlob, fragGlob, 1)
			replaced = true
		}
	}

	if !replaced {
		cleanup()

		return "", nil, fmt.Errorf("base nginx.conf %s has no `include %s;` line; cannot build a synthetic validation config", c.cfg.NginxConfPath, liveGlob)
	}

	syntheticPath := filepath.Join(tmpDir, "nginx.conf")
	if err := os.WriteFile(syntheticPath, []byte(strings.Join(lines, "\n")), 0o644); err != nil {
		cleanup()

		return "", nil, fmt.Errorf("writing synthetic nginx.conf: %w", err)
	}

	return syntheticPath, cleanup, nil
}
