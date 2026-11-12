// Package atomicfile writes a file so that a reader never sees it half written.
package atomicfile

import (
	"fmt"
	"os"
	"os/user"
	"path/filepath"
	"strconv"
)

// Write puts content at path with exactly mode. It is written to path+".tmp" beside it, flushed to
// disk, and renamed into place, which is atomic on one filesystem; the temporary file is removed
// when anything fails. The directory is created when it is missing.
func Write(path string, content []byte, mode os.FileMode) error {
	return WriteOwned(path, content, mode, "")
}

// WriteOwned is Write for a file owned by root and the named group (no change of owner when group is
// empty). The owner is set before the rename: the appliers run without CAP_FSETID, so a setgid
// directory would not pass the group down.
func WriteOwned(path string, content []byte, mode os.FileMode, group string) error {
	gid := -1

	if group != "" {
		g, err := user.LookupGroup(group)
		if err != nil {
			return fmt.Errorf("looking up group %s: %w", group, err)
		}

		gid, err = strconv.Atoi(g.Gid)
		if err != nil {
			return fmt.Errorf("group %s has a non-numeric id %q", group, g.Gid)
		}
	}

	if err := os.MkdirAll(filepath.Dir(path), 0o755); err != nil {
		return fmt.Errorf("creating directory for %s: %w", path, err)
	}

	tmp := path + ".tmp"

	if err := writeSynced(tmp, content, mode); err != nil {
		_ = os.Remove(tmp)

		return err
	}

	if gid >= 0 {
		if err := os.Chown(tmp, 0, gid); err != nil {
			_ = os.Remove(tmp)

			return fmt.Errorf("setting the owner of %s: %w", tmp, err)
		}
	}

	if err := os.Rename(tmp, path); err != nil {
		_ = os.Remove(tmp)

		return fmt.Errorf("activating %s: %w", path, err)
	}

	return nil
}

func writeSynced(path string, content []byte, mode os.FileMode) error {
	f, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, mode)
	if err != nil {
		return fmt.Errorf("writing staged file %s: %w", path, err)
	}

	if _, err := f.Write(content); err != nil {
		_ = f.Close()

		return fmt.Errorf("writing staged file %s: %w", path, err)
	}

	if err := f.Sync(); err != nil {
		_ = f.Close()

		return fmt.Errorf("flushing staged file %s: %w", path, err)
	}

	if err := f.Close(); err != nil {
		return fmt.Errorf("writing staged file %s: %w", path, err)
	}

	// The creation mode is cut by the umask; the file is to have exactly mode.
	if err := os.Chmod(path, mode); err != nil {
		return fmt.Errorf("setting the mode of %s: %w", path, err)
	}

	return nil
}
