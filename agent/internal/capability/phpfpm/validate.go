package phpfpm

import (
	"context"
	"fmt"
	"os"
	"os/user"
	"path/filepath"
	"strings"

	"github.com/mikho/LESta/agent/internal/capability/fragment"
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
		message := strings.TrimSpace(string(out))
		if message == "" {
			// php-fpm -t produced no stdout/stderr at all before failing --
			// most likely the context deadline was reached before the
			// command could even start or run to completion (e.g. a slow
			// operation dispatched earlier in the same batch consumed this
			// one's own remaining time budget), not a genuine config
			// rejection. Falling through to an empty message would make
			// this indistinguishable from a real syntax error.
			message = fmt.Sprintf("%s produced no output: %v", phpFpmBinary(version), err)
		}

		return &ValidationError{
			Code:    "php_fpm_config_invalid",
			Message: message,
		}
	}

	return nil
}

// buildSyntheticConfig copies the real (read-only) php-fpm.conf for version
// and substitutes its `include=<poolDir>/*.conf` line for one pointing at a
// scratch directory of every other currently-live pool for that same
// version, plus the candidate (if any). The returned cleanup func removes
// the entire scratch tree; callers must defer it.
//
// php-fpm -t refuses to validate a config whose include glob matches zero
// pools ("No pool defined"), which real deletes hit whenever the resource
// being removed is the last live pool for that version. A syntax-only
// placeholder pool, never written to the real poolDir, keeps the synthetic
// config non-empty without affecting the verdict on the candidate itself.
func (c *PhpFpmCapability) buildSyntheticConfig(version, resourceID, candidatePath string) (string, func(), error) {
	return fragment.SyntheticConfig{
		Service:          "phpfpm",
		BaseConfPath:     c.cfg.fpmConfigPath(version),
		BaseConfName:     "php-fpm.conf",
		Dir:              c.pools(version),
		FragmentsDirName: "pool.d",
		IncludeKeyword:   "include=",
		Placeholder:      writePlaceholderPool,
		EditLines: func(lines []string, tmpDir string) []string {
			return redirectGlobalLog(lines, filepath.Join(tmpDir, "php-fpm.log"))
		},
	}.Build(resourceID, candidatePath)
}

// writePlaceholderPool renders the placeholder pool into fragDir. It runs as
// whoever is validating, a user that always exists.
func writePlaceholderPool(fragDir, tmpDir string) error {
	self, err := user.Current()
	if err != nil {
		return fmt.Errorf("looking up the validating user: %w", err)
	}

	placeholder, err := renderPool(poolData{
		ResourceID:      "lesta-validate-placeholder",
		AccountUsername: self.Username,
		SocketPath:      filepath.Join(tmpDir, "placeholder.sock"),
		Docroot:         tmpDir,
	})
	if err != nil {
		return fmt.Errorf("rendering placeholder pool: %w", err)
	}

	if err := os.WriteFile(filepath.Join(fragDir, "lesta-validate-placeholder.conf"), placeholder, 0o644); err != nil {
		return fmt.Errorf("writing placeholder pool: %w", err)
	}

	return nil
}

// redirectGlobalLog points the synthetic configuration's global error_log at the scratch directory.
// php-fpm -t opens the error log named in the global section, so a test run would otherwise write
// to (and need write access to) the real log of the running service.
func redirectGlobalLog(lines []string, logPath string) []string {
	out := make([]string, len(lines))

	for i, line := range lines {
		trimmed := strings.TrimSpace(line)

		if strings.HasPrefix(trimmed, "error_log") && strings.Contains(trimmed, "=") && !strings.HasPrefix(trimmed, ";") {
			out[i] = "error_log = " + logPath

			continue
		}

		out[i] = line
	}

	return out
}
