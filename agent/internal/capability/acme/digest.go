package acme

import (
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
	"sort"
	"strings"
)

// servedSubdirs are the only two subpaths of StateRoot computeDigest
// fingerprints: the actual served state this capability writes
// (StateRoot/http-01/<token> challenge files, StateRoot/certs/<domain>/*.pem
// bundles). StateRoot/domains -- generation.Store's own bookkeeping
// (manifests, current/previous symlinks, per-generation sidecar metadata) --
// is deliberately excluded: it isn't served state, it's this capability's own
// idempotency/observe ledger, and its symlinks would break a naive recursive
// file read besides.
var servedSubdirs = []string{"http-01", "certs"}

// computeDigest fingerprints every regular file nested anywhere under
// StateRoot's servedSubdirs, the same (hash, relative-path) manifest
// technique internal/generation.ComputeDigest uses for nginx/apache/bind9's
// own flat, *.conf-only owned roots -- generalized here since this
// capability's own served state is neither flat nor *.conf-suffixed, so
// generation.ComputeDigest itself (a non-recursive *.conf glob over one
// directory) cannot be reused directly. A subdirectory that doesn't exist yet
// digests the same as an empty one, matching generation.ComputeDigest's own
// "no fragments yet" behavior.
func computeDigest(stateRoot string) (string, error) {
	var paths []string

	for _, sub := range servedSubdirs {
		dir := filepath.Join(stateRoot, sub)

		if _, statErr := os.Stat(dir); statErr == nil {
			walkErr := filepath.WalkDir(dir, func(path string, d fs.DirEntry, err error) error {
				if err != nil {
					return err
				}
				if d.IsDir() {
					return nil
				}

				paths = append(paths, path)

				return nil
			})
			if walkErr != nil {
				return "", fmt.Errorf("walking %s: %w", dir, walkErr)
			}
		} else if !os.IsNotExist(statErr) {
			return "", fmt.Errorf("checking %s: %w", dir, statErr)
		}
	}

	sort.Strings(paths)

	var manifest strings.Builder

	for _, path := range paths {
		content, err := os.ReadFile(path)
		if err != nil {
			return "", fmt.Errorf("reading %s: %w", path, err)
		}

		rel, err := filepath.Rel(stateRoot, path)
		if err != nil {
			return "", fmt.Errorf("computing relative path for %s: %w", path, err)
		}

		sum := sha256.Sum256(content)
		fmt.Fprintf(&manifest, "%s  %s\n", hex.EncodeToString(sum[:]), filepath.ToSlash(rel))
	}

	final := sha256.Sum256([]byte(manifest.String()))

	return "sha256:" + hex.EncodeToString(final[:]), nil
}
