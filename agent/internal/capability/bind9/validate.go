package bind9

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
)

// placeholderFragmentName is a fixed, never-removed *.conf fragment in
// LiveDir. Verified directly against the local BIND9 install: named's own
// `include "<dir>/*.conf";` glob-include fails outright ("file not found")
// if the glob matches zero files, unlike nginx's equivalent include, which
// tolerates an empty match. Since a freshly-provisioned node (or a fresh
// disposable test instance) legitimately starts with zero zones, LiveDir
// must always contain at least one matching file, or named itself would
// fail to start/reload before this capability ever gets a chance to create
// the first real one. The leading underscore keeps it sorted first and
// visually distinct from real <resource_id>.conf fragments.
const placeholderFragmentName = "_lesta-placeholder.conf"

// ensurePlaceholderFragment writes the placeholder fragment into dir if no
// *.conf file exists there yet. It is idempotent and safe to call before
// every validation attempt; the file's own content never changes once
// written, so it contributes a fixed, stable term to the whole-dir digest.
func ensurePlaceholderFragment(dir string) error {
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return fmt.Errorf("creating live directory %s: %w", dir, err)
	}

	matches, err := filepath.Glob(filepath.Join(dir, "*.conf"))
	if err != nil {
		return fmt.Errorf("globbing %s for *.conf fragments: %w", dir, err)
	}
	if len(matches) > 0 {
		return nil
	}

	content := "# Managed by LESta. Do not remove: keeps named's glob include\n" +
		"# valid when no zones have been created yet.\n"

	if err := os.WriteFile(filepath.Join(dir, placeholderFragmentName), []byte(content), 0o644); err != nil {
		return fmt.Errorf("writing placeholder fragment in %s: %w", dir, err)
	}

	return nil
}

// validateCandidate tests a candidate stanza without disturbing the live
// config: it builds a synthetic full named.conf that includes every other
// currently-live fragment plus candidateStanzaPath (renamed to
// <resourceID>.conf, so named's own glob picks it up inside the scratch
// directory), then runs `named-checkconf -z <synthetic config>`. That single
// call performs a full check: config structure AND every referenced zone
// file's actual content (verified directly against the local install), so no
// separate named-checkzone invocation is needed.
//
// candidateStanzaPath == "" means "validate this resource's stanza as
// absent" (used by delete and by a suspended create/update): every other
// live fragment is included, this resource's own is not.
//
// A non-nil *ValidationError means named-checkconf rejected the config (its
// own combined stderr/stdout is the message); any other non-nil error means
// the harness itself couldn't run (a "no verdict reached" infrastructure
// failure, not a business rejection).
func (c *Bind9Capability) validateCandidate(ctx context.Context, resourceID string, candidateStanzaPath string) error {
	syntheticConfPath, cleanup, err := c.buildSyntheticConfig(resourceID, candidateStanzaPath)
	if err != nil {
		return err
	}
	defer cleanup()

	cmd := exec.CommandContext(ctx, c.cfg.namedCheckconfBinary(), "-z", syntheticConfPath)
	out, err := cmd.CombinedOutput()
	if err != nil {
		return &ValidationError{
			Code:    "bind9_config_invalid",
			Message: strings.TrimSpace(string(out)),
		}
	}

	return nil
}

// buildSyntheticConfig copies the real (read-only) named.conf and
// substitutes its `include "<LiveDir>/*.conf";` line for one pointing at a
// fresh scratch directory populated with symlinks to every other currently-
// live fragment, plus the candidate (if any). Absolute zone-file paths
// outside the synthetic config's own directory are standard, well-supported
// BIND behavior (verified directly against the local install), so the zone
// data file itself needs no scratch-dir handling; only the stanza does. The
// returned cleanup func removes the entire scratch tree; callers must defer
// it.
func (c *Bind9Capability) buildSyntheticConfig(resourceID string, candidateStanzaPath string) (string, func(), error) {
	if err := ensurePlaceholderFragment(c.cfg.LiveDir); err != nil {
		return "", nil, err
	}

	tmpDir, err := os.MkdirTemp("", "lesta-bind9-validate-")
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

	if candidateStanzaPath != "" {
		if err := os.Symlink(candidateStanzaPath, filepath.Join(fragDir, excludeName)); err != nil {
			cleanup()

			return "", nil, fmt.Errorf("symlinking candidate fragment %s: %w", candidateStanzaPath, err)
		}
	}

	baseConf, err := os.ReadFile(c.cfg.NamedConfPath)
	if err != nil {
		cleanup()

		return "", nil, fmt.Errorf("reading base named.conf %s: %w", c.cfg.NamedConfPath, err)
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

		return "", nil, fmt.Errorf("base named.conf %s has no `include %q;` line; cannot build a synthetic validation config", c.cfg.NamedConfPath, liveGlob)
	}

	syntheticPath := filepath.Join(tmpDir, "named.conf")
	if err := os.WriteFile(syntheticPath, []byte(strings.Join(lines, "\n")), 0o644); err != nil {
		cleanup()

		return "", nil, fmt.Errorf("writing synthetic named.conf: %w", err)
	}

	return syntheticPath, cleanup, nil
}
