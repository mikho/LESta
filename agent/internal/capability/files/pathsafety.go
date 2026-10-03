package files

import (
	"os"
	"path/filepath"
	"strings"
)

// resolveSafePath joins relPath onto docroot and rejects anything that
// would resolve outside it (a ".." sequence, an absolute-looking segment,
// etc.) -- filepath.Join already Cleans the combined path internally, so
// the escape attempt is caught by the prefix check below, never by
// string-matching ".." in the input itself (a well-known, bypassable
// approach this deliberately avoids). relPath == "" means "the docroot
// itself" (listing its own top level).
//
// Does not touch the filesystem at all: safe to call for a path that does
// not exist yet (a create target, or a rename's own destination).
func resolveSafePath(docroot, relPath string) (string, error) {
	if relPath == "" {
		return docroot, nil
	}

	joined := filepath.Join(docroot, relPath)

	if joined != docroot && !strings.HasPrefix(joined, docroot+string(filepath.Separator)) {
		return "", errPathEscapesDocroot
	}

	return joined, nil
}

// resolveExistingSafePath additionally resolves symlinks on a path that is
// expected to already exist (observe, delete, update's own content-
// overwrite and rename-source targets), and rechecks containment on the
// resolved result: a tenant-uploaded symlink pointing outside the docroot
// (e.g. via SFTP, before this capability ever existed) must never let a
// read/write/delete operation follow it out.
//
// docroot itself is also resolved through EvalSymlinks before the
// containment check, not just relPath's own target: on a system where
// docroot's own ancestry includes a symlink (confirmed for real on macOS,
// where a test's own t.TempDir() lands under /var/folders, itself a
// symlink to /private/var/folders), comparing an already-resolved target
// against an unresolved docroot prefix would reject every legitimate path.
func resolveExistingSafePath(docroot, relPath string) (string, error) {
	resolvedDocroot, err := filepath.EvalSymlinks(docroot)
	if err != nil {
		return "", err
	}

	joined, err := resolveSafePath(docroot, relPath)
	if err != nil {
		return "", err
	}

	resolved, err := filepath.EvalSymlinks(joined)
	if err != nil {
		return "", err
	}

	if resolved != resolvedDocroot && !strings.HasPrefix(resolved, resolvedDocroot+string(filepath.Separator)) {
		return "", errPathEscapesDocroot
	}

	return resolved, nil
}

// resolveSafeParent resolves relPath's own parent directory the same way
// resolveExistingSafePath does (symlinks followed, containment rechecked
// against docroot's own resolved form), for a path that does not exist yet
// (a create target, or a rename's own destination) but whose parent must
// already exist and must not itself be a symlink escaping the docroot.
func resolveSafeParent(docroot, relPath string) (parent, resolvedTarget string, err error) {
	resolvedDocroot, err := filepath.EvalSymlinks(docroot)
	if err != nil {
		return "", "", err
	}

	joined, err := resolveSafePath(docroot, relPath)
	if err != nil {
		return "", "", err
	}

	parentDir := filepath.Dir(joined)

	resolvedParent, err := filepath.EvalSymlinks(parentDir)
	if err != nil {
		return "", "", err
	}

	if resolvedParent != resolvedDocroot && !strings.HasPrefix(resolvedParent, resolvedDocroot+string(filepath.Separator)) {
		return "", "", errPathEscapesDocroot
	}

	return resolvedParent, filepath.Join(resolvedParent, filepath.Base(joined)), nil
}

// statType reports "directory"/"file"/"" (anything else -- a socket, device,
// etc., deliberately never exposed as a browsable type) for a resolved path.
func statType(path string) (string, os.FileInfo, error) {
	info, err := os.Lstat(path)
	if err != nil {
		return "", nil, err
	}

	switch {
	case info.IsDir():
		return "directory", info, nil
	case info.Mode().IsRegular():
		return "file", info, nil
	default:
		return "", info, nil
	}
}
