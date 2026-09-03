package cron

import (
	"fmt"
	"os"
	"path/filepath"
)

// writeFileAtomic writes content to path by first writing to a sibling
// ".tmp" file in the same directory (guaranteeing the subsequent os.Rename
// is on the same filesystem, so it's atomic: a reader, including cron's own
// polling daemon, never observes a partially-written file at path), then
// renaming it into place. Mirrors internal/capability/acme's own helper of
// the same name and shape (copied here rather than imported, matching this
// project's own "each capability package is self-contained" convention).
func writeFileAtomic(path string, content []byte, mode os.FileMode) error {
	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return fmt.Errorf("creating directory for %s: %w", path, err)
	}

	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, content, mode); err != nil {
		return fmt.Errorf("writing staged file for %s: %w", path, err)
	}

	if err := os.Rename(tmp, path); err != nil {
		return fmt.Errorf("activating %s: %w", path, err)
	}

	return nil
}
