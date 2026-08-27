package nginx

import (
	"context"
	"fmt"
	"os"
	"os/exec"
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

	args := c.cfg.commandArgs("-t", "-c", syntheticConfPath)

	cmd := exec.CommandContext(ctx, c.cfg.nginxBinary(), args...)
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
