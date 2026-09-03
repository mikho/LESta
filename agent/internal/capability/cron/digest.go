package cron

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
)

// computeDigest fingerprints the live "lesta-*" crontab fragments in dir
// (this capability's own FragmentDir, /etc/cron.d in production). This
// mirrors internal/generation.ComputeDigest's own algorithm exactly (sort
// every fragment's relative path, hash each file's content, then hash a
// sha256sum(1)-format manifest of (hash, path) pairs), just re-implemented
// here with a "lesta-*" glob instead of that shared function's hardcoded
// "*.conf" glob, which does not match this capability's own fragment naming
// (a bare crontab fragment file has no extension at all). Digests the whole
// owned directory, not just one resource's own file, mirroring nginx's/
// bind9's own "whole owned directory" digest philosophy.
//
// The returned digest is always of the form "sha256:<64 lowercase hex
// chars>", even when dir contains no fragments at all.
func computeDigest(dir string) (string, error) {
	matches, err := filepath.Glob(filepath.Join(dir, "lesta-*"))
	if err != nil {
		return "", fmt.Errorf("globbing %s for lesta-* fragments: %w", dir, err)
	}

	sort.Strings(matches)

	var manifest strings.Builder

	for _, path := range matches {
		content, err := os.ReadFile(path)
		if err != nil {
			return "", fmt.Errorf("reading fragment %s: %w", path, err)
		}

		rel, err := filepath.Rel(dir, path)
		if err != nil {
			return "", fmt.Errorf("computing relative path for %s: %w", path, err)
		}

		sum := sha256.Sum256(content)
		fmt.Fprintf(&manifest, "%s  %s\n", hex.EncodeToString(sum[:]), rel)
	}

	final := sha256.Sum256([]byte(manifest.String()))

	return "sha256:" + hex.EncodeToString(final[:]), nil
}
