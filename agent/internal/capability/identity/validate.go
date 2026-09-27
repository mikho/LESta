package identity

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
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

	cmd := exec.CommandContext(ctx, c.cfg.sshdBinary(), "-t", "-f", syntheticConfPath)

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
// at a fresh scratch directory populated with symlinks to every other
// currently-live account fragment, plus the candidate (if any). The returned
// cleanup func removes the entire scratch tree; callers must defer it.
func (c *IdentityCapability) buildSyntheticConfig(resourceID string, candidatePath string) (string, func(), error) {
	tmpDir, err := os.MkdirTemp("", "lesta-identity-validate-")
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

	existing, err := filepath.Glob(filepath.Join(c.cfg.SftpConfigDir, "*.conf"))
	if err != nil {
		cleanup()

		return "", nil, fmt.Errorf("listing live fragments in %s: %w", c.cfg.SftpConfigDir, err)
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

	baseConf, err := os.ReadFile(c.cfg.SshdConfigPath)
	if err != nil {
		cleanup()

		return "", nil, fmt.Errorf("reading base sshd_config %s: %w", c.cfg.SshdConfigPath, err)
	}

	liveGlob := filepath.Join(c.cfg.SftpConfigDir, "*.conf")
	fragGlob := filepath.Join(fragDir, "*.conf")

	lines := strings.Split(string(baseConf), "\n")
	replaced := false

	for i, line := range lines {
		if strings.Contains(line, "Include") && strings.Contains(line, liveGlob) {
			lines[i] = strings.Replace(line, liveGlob, fragGlob, 1)
			replaced = true
		}
	}

	if !replaced {
		cleanup()

		return "", nil, fmt.Errorf("base sshd_config %s has no `Include %s` line; cannot build a synthetic validation config", c.cfg.SshdConfigPath, liveGlob)
	}

	syntheticPath := filepath.Join(tmpDir, "sshd_config")
	if err := os.WriteFile(syntheticPath, []byte(strings.Join(lines, "\n")), 0o644); err != nil {
		cleanup()

		return "", nil, fmt.Errorf("writing synthetic sshd_config: %w", err)
	}

	return syntheticPath, cleanup, nil
}
