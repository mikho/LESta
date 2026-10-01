package phpfpm

import (
	"context"
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

// validateCandidate tests a candidate pool fragment for version without
// disturbing the live pool.d directory: it builds a synthetic full
// php-fpm.conf that includes every other currently-live pool for that same
// version plus candidatePath (renamed to <resourceID>.conf, so php-fpm's
// own glob include picks it up inside the scratch directory), then runs
// `php-fpm<version> -t -y <synthetic config>`.
//
// candidatePath == "" means "validate this resource's fragment as absent"
// (used by delete): every other live pool for that version is included,
// this resource's own is not.
//
// A non-nil *ValidationError means php-fpm rejected the config (its own
// stderr is the message); any other non-nil error means the harness itself
// couldn't run (a "no verdict reached" infrastructure failure, not a
// business rejection).
func (c *PhpFpmCapability) validateCandidate(ctx context.Context, version, resourceID, candidatePath string) error {
	syntheticConfPath, cleanup, err := c.buildSyntheticConfig(version, resourceID, candidatePath)
	if err != nil {
		return err
	}
	defer cleanup()

	cmd := c.cfg.command(ctx, phpFpmBinary(version), "-t", "-y", syntheticConfPath)

	out, err := cmd.CombinedOutput()
	if err != nil {
		return &ValidationError{
			Code:    "php_fpm_config_invalid",
			Message: strings.TrimSpace(string(out)),
		}
	}

	return nil
}

// buildSyntheticConfig copies the real (read-only) php-fpm.conf for version
// and substitutes its `include=<poolDir>/*.conf` line for one pointing at a
// fresh scratch directory populated with symlinks to every other
// currently-live pool for that same version, plus the candidate (if any).
// The returned cleanup func removes the entire scratch tree; callers must
// defer it.
func (c *PhpFpmCapability) buildSyntheticConfig(version, resourceID, candidatePath string) (string, func(), error) {
	tmpDir, err := os.MkdirTemp("", "lesta-phpfpm-validate-")
	if err != nil {
		return "", nil, fmt.Errorf("creating validation scratch directory: %w", err)
	}

	cleanup := func() { _ = os.RemoveAll(tmpDir) }

	fragDir := filepath.Join(tmpDir, "pool.d")
	if err := os.Mkdir(fragDir, 0o755); err != nil {
		cleanup()

		return "", nil, fmt.Errorf("creating pool.d scratch directory: %w", err)
	}

	excludeName := resourceID + ".conf"
	poolDir := c.cfg.poolDir(version)

	existing, err := filepath.Glob(filepath.Join(poolDir, "*.conf"))
	if err != nil {
		cleanup()

		return "", nil, fmt.Errorf("listing live pools in %s: %w", poolDir, err)
	}

	for _, f := range existing {
		if filepath.Base(f) == excludeName {
			continue
		}

		if err := os.Symlink(f, filepath.Join(fragDir, filepath.Base(f))); err != nil {
			cleanup()

			return "", nil, fmt.Errorf("symlinking live pool %s: %w", f, err)
		}
	}

	if candidatePath != "" {
		if err := os.Symlink(candidatePath, filepath.Join(fragDir, excludeName)); err != nil {
			cleanup()

			return "", nil, fmt.Errorf("symlinking candidate pool %s: %w", candidatePath, err)
		}
	}

	fpmConfigPath := c.cfg.fpmConfigPath(version)

	baseConf, err := os.ReadFile(fpmConfigPath)
	if err != nil {
		cleanup()

		return "", nil, fmt.Errorf("reading base php-fpm.conf %s: %w", fpmConfigPath, err)
	}

	liveGlob := filepath.Join(poolDir, "*.conf")
	fragGlob := filepath.Join(fragDir, "*.conf")

	lines := strings.Split(string(baseConf), "\n")
	replaced := false

	for i, line := range lines {
		if strings.Contains(line, "include=") && strings.Contains(line, liveGlob) {
			lines[i] = strings.Replace(line, liveGlob, fragGlob, 1)
			replaced = true
		}
	}

	if !replaced {
		cleanup()

		return "", nil, fmt.Errorf("base php-fpm.conf %s has no `include=%s` line; cannot build a synthetic validation config", fpmConfigPath, liveGlob)
	}

	syntheticPath := filepath.Join(tmpDir, "php-fpm.conf")
	if err := os.WriteFile(syntheticPath, []byte(strings.Join(lines, "\n")), 0o644); err != nil {
		cleanup()

		return "", nil, fmt.Errorf("writing synthetic php-fpm.conf: %w", err)
	}

	return syntheticPath, cleanup, nil
}
