package apache

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
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
// at a fresh scratch directory populated with symlinks to every other
// currently-live fragment, plus the candidate (if any). The substituted line
// is always rewritten as IncludeOptional, regardless of the original
// directive's own spelling: a synthetic directory with zero fragments (e.g.
// validating a delete that removes the last resource) must never hard-fail the
// way a plain Include does when its glob matches nothing (verified directly:
// `apache2 -t` errors "No matches for the wildcard ... failing" for a bare
// Include, but reports Syntax OK for IncludeOptional against the same empty
// directory). The returned cleanup func removes the entire scratch tree;
// callers must defer it.
func (c *ApacheCapability) buildSyntheticConfig(resourceID string, candidatePath string) (string, func(), error) {
	tmpDir, err := os.MkdirTemp("", "lesta-apache-validate-")
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

	baseConf, err := os.ReadFile(c.cfg.ApacheConfPath)
	if err != nil {
		cleanup()

		return "", nil, fmt.Errorf("reading base apache2.conf %s: %w", c.cfg.ApacheConfPath, err)
	}

	liveGlob := filepath.Join(c.cfg.LiveDir, "*.conf")
	fragGlob := filepath.Join(fragDir, "*.conf")

	lines := strings.Split(string(baseConf), "\n")
	replaced := false

	for i, line := range lines {
		if strings.Contains(line, "Include") && strings.Contains(line, liveGlob) {
			lines[i] = "IncludeOptional " + fragGlob
			replaced = true
		}
	}

	if !replaced {
		cleanup()

		return "", nil, fmt.Errorf("base apache2.conf %s has no `Include`/`IncludeOptional %s` line; cannot build a synthetic validation config", c.cfg.ApacheConfPath, liveGlob)
	}

	syntheticPath := filepath.Join(tmpDir, "apache2.conf")
	if err := os.WriteFile(syntheticPath, []byte(strings.Join(lines, "\n")), 0o644); err != nil {
		cleanup()

		return "", nil, fmt.Errorf("writing synthetic apache2.conf: %w", err)
	}

	return syntheticPath, cleanup, nil
}
