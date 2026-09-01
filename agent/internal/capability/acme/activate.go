package acme

import (
	"fmt"
	"os"
	"path/filepath"
)

// writeFileAtomic writes content to path by first writing to a sibling
// ".tmp" file in the same directory (guaranteeing the subsequent os.Rename
// is on the same filesystem, so it's atomic: a reader never observes a
// partially-written file at path), then renaming it into place. Every other
// capability splits this into a separate "write staging, validate, then
// activate" sequence because each has an external validator binary to run in
// between; this capability never does (there is nothing to validate a plain
// file write against), so the write-then-rename collapses into one helper.
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
