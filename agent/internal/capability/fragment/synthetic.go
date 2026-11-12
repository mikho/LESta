package fragment

import (
	"fmt"
	"os"
	"path/filepath"
	"strings"
)

// SyntheticConfig describes how to test a candidate fragment without disturbing the live config:
// a copy of the service's real main config whose include line points at a scratch directory
// populated with symlinks to every other currently-live fragment plus the candidate, which the
// service's own checker (`nginx -t`, `named-checkconf -z`, ...) is then run against.
type SyntheticConfig struct {
	// Service names the scratch directory, lesta-<Service>-validate-.
	Service string

	// BaseConfPath is the real, read-only main config; BaseConfName is the synthetic copy's file
	// name ("nginx.conf").
	BaseConfPath string
	BaseConfName string

	// Dir holds the live fragments.
	Dir Dir

	// FragmentsDirName is the scratch directory's name for the fragments ("fragments", or what
	// the service expects, "pool.d").
	FragmentsDirName string

	// IncludeKeyword is what the include line contains besides the live glob: "include",
	// "Include", "include=".
	IncludeKeyword string

	// RewriteInclude writes the include line that points at the scratch glob. Nil replaces the
	// live glob with the scratch glob in place.
	RewriteInclude func(line, liveGlob, fragGlob string) string

	// Placeholder, when set, is called when no fragment at all ends up in the scratch directory
	// (the candidate is an absence and no other fragment is live), for a service whose checker
	// refuses an empty include.
	Placeholder func(fragDir, tmpDir string) error

	// EditLines, when set, adjusts the synthetic config's lines before they are written.
	EditLines func(lines []string, tmpDir string) []string
}

// Build populates the scratch directory and writes the synthetic config, returning its path. The
// returned cleanup func removes the entire scratch tree; callers must defer it.
//
// candidatePath == "" means "validate this resource's fragment as absent" (used by delete): every
// other live fragment is included, this resource's own is not.
func (s SyntheticConfig) Build(resourceID, candidatePath string) (string, func(), error) {
	tmpDir, err := os.MkdirTemp("", "lesta-"+s.Service+"-validate-")
	if err != nil {
		return "", nil, fmt.Errorf("creating validation scratch directory: %w", err)
	}

	cleanup := func() { _ = os.RemoveAll(tmpDir) }

	fragDir := filepath.Join(tmpDir, s.FragmentsDirName)
	if err := os.Mkdir(fragDir, 0o755); err != nil {
		cleanup()

		return "", nil, fmt.Errorf("creating %s scratch directory: %w", s.FragmentsDirName, err)
	}

	excludeName := resourceID + ".conf"

	existing, err := filepath.Glob(filepath.Join(s.Dir.Path, "*.conf"))
	if err != nil {
		cleanup()

		return "", nil, fmt.Errorf("listing live fragments in %s: %w", s.Dir.Path, err)
	}

	included := 0

	for _, f := range existing {
		if filepath.Base(f) == excludeName {
			continue
		}

		included++

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

	if included == 0 && candidatePath == "" && s.Placeholder != nil {
		if err := s.Placeholder(fragDir, tmpDir); err != nil {
			cleanup()

			return "", nil, err
		}
	}

	baseConf, err := os.ReadFile(s.BaseConfPath)
	if err != nil {
		cleanup()

		return "", nil, fmt.Errorf("reading base %s %s: %w", s.BaseConfName, s.BaseConfPath, err)
	}

	liveGlob := filepath.Join(s.Dir.Path, "*.conf")
	fragGlob := filepath.Join(fragDir, "*.conf")

	lines := strings.Split(string(baseConf), "\n")
	replaced := false

	for i, line := range lines {
		if strings.Contains(line, s.IncludeKeyword) && strings.Contains(line, liveGlob) {
			if s.RewriteInclude != nil {
				lines[i] = s.RewriteInclude(line, liveGlob, fragGlob)
			} else {
				lines[i] = strings.Replace(line, liveGlob, fragGlob, 1)
			}

			replaced = true
		}
	}

	if !replaced {
		cleanup()

		return "", nil, fmt.Errorf("base %s %s has no `%s` line including %s; cannot build a synthetic validation config", s.BaseConfName, s.BaseConfPath, s.IncludeKeyword, liveGlob)
	}

	if s.EditLines != nil {
		lines = s.EditLines(lines, tmpDir)
	}

	syntheticPath := filepath.Join(tmpDir, s.BaseConfName)
	if err := os.WriteFile(syntheticPath, []byte(strings.Join(lines, "\n")), 0o644); err != nil {
		cleanup()

		return "", nil, fmt.Errorf("writing synthetic %s: %w", s.BaseConfName, err)
	}

	return syntheticPath, cleanup, nil
}
