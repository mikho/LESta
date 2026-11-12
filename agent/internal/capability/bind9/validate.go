package bind9

import (
	"context"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"

	"github.com/mikho/LESta/agent/internal/capability/fragment"
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
// scratch directory of every other currently-live fragment plus the
// candidate (if any). Absolute zone-file paths outside the synthetic
// config's own directory are standard, well-supported BIND behavior
// (verified directly against the local install), so the zone data file
// itself needs no scratch-dir handling; only the stanza does. The returned
// cleanup func removes the entire scratch tree; callers must defer it.
func (c *Bind9Capability) buildSyntheticConfig(resourceID string, candidateStanzaPath string) (string, func(), error) {
	if err := ensurePlaceholderFragment(c.cfg.LiveDir); err != nil {
		return "", nil, err
	}

	return fragment.SyntheticConfig{
		Service:          "bind9",
		BaseConfPath:     c.cfg.NamedConfPath,
		BaseConfName:     "named.conf",
		Dir:              c.dir(),
		FragmentsDirName: "fragments",
		IncludeKeyword:   "include",
	}.Build(resourceID, candidateStanzaPath)
}
