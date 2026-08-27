package generation

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"os"
	"path/filepath"
	"sort"
	"strings"
)

// ComputeDigest fingerprints the live *.conf fragments in dir (a capability's own
// "lesta.d"-equivalent owned root). It is service-agnostic: any capability that
// owns a directory of *.conf fragments can use it.
//
// The algorithm: sort every fragment's path (relative to dir), hash each file's
// content, then hash a sha256sum(1)-format manifest of (hash, path) pairs. Hashing
// a manifest of (hash, path) pairs, rather than raw concatenated file bytes, is
// deliberate: path has to be part of the hashed identity, or two byte-identical
// fragments belonging to different domains would digest the same. The manifest
// format matches coreutils' sha256sum output line-for-line, so it is reproducible
// by hand (`sha256sum lesta.d/*.conf`) by a human investigating drift, not just by
// this Go code.
//
// The returned digest is always of the form "sha256:<64 lowercase hex chars>",
// even when dir contains no fragments at all (an empty manifest hashes to a fixed,
// well-defined digest).
func ComputeDigest(dir string) (string, error) {
	matches, err := filepath.Glob(filepath.Join(dir, "*.conf"))
	if err != nil {
		return "", fmt.Errorf("globbing %s for *.conf fragments: %w", dir, err)
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
