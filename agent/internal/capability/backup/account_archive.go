package backup

import (
	"archive/tar"
	"compress/gzip"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"io/fs"
	"os"
	"path"
	"path/filepath"
	"sort"
	"strings"
	"syscall"
	"time"
)

// This file builds and reads per-account backups: tar inside gzip inside the
// sealed stream of stream.go. Entry names are "manifest.json" first, then
// "databases/<name>.sql", "mail/<domain>/...", and "files/<resource id>/...",
// written in that order so a restore can finish the parts that need root
// before it gives root up for the tenant's own files.
//
// Nothing in an archive is trusted to name a destination: the destination of
// every entry is built from a validated part root plus the cleaned remainder of
// the entry name, links are only recreated when they stay inside the part, and
// the tenant's files are written only after dropping to the tenant's own
// user, so a link planted in a live site cannot be used to write elsewhere.

const (
	partFiles     = "files"
	partDatabases = "databases"
	partMail      = "mail"

	manifestName = "manifest.json"

	// defaultMaxSourceBytes is how much source data one account backup may hold.
	defaultMaxSourceBytes int64 = 5 << 30
)

// ErrBackupTooLarge is returned when an account's data exceeds the size limit.
var ErrBackupTooLarge = errors.New("the account's data is larger than the backup size limit")

// accountManifest is the first entry of every archive.
type accountManifest struct {
	Version  int      `json:"version"`
	Username string   `json:"username"`
	Parts    []string `json:"parts"`
	Created  string   `json:"created"`
}

// dbDump is a database already dumped to a local file by the privileged caller.
type dbDump struct {
	Name string
	Path string
}

// archiveSource is everything one account archive is built from.
type archiveSource struct {
	Username  string
	Databases []dbDump
	// MailDirs maps a mail domain to its directory under the vmail root.
	MailDirs map[string]string
	// DocRoots maps a web resource id to that site's folder.
	DocRoots map[string]string
	// DropPrivileges runs once, before the tenant's own files are read. Nil
	// outside production.
	DropPrivileges func() error
	MaxBytes       int64
}

// archiveReport is what an archive contained.
type archiveReport struct {
	Parts   map[string]partReport `json:"parts"`
	Skipped []string              `json:"skipped,omitempty"`
}

type partReport struct {
	Entries int   `json:"entries"`
	Bytes   int64 `json:"bytes"`
}

// writeAccountArchive writes src as a sealed archive into w.
func writeAccountArchive(w io.Writer, hexKey string, src archiveSource) (archiveReport, error) {
	report := archiveReport{Parts: map[string]partReport{}}

	sw, err := newSealWriter(w, hexKey)
	if err != nil {
		return report, err
	}

	gz := gzip.NewWriter(sw)
	tw := tar.NewWriter(gz)

	maxBytes := src.MaxBytes
	if maxBytes <= 0 {
		maxBytes = defaultMaxSourceBytes
	}

	var total int64

	count := func(part string, n int64) error {
		p := report.Parts[part]
		p.Entries++
		p.Bytes += n
		report.Parts[part] = p

		total += n
		if total > maxBytes {
			return ErrBackupTooLarge
		}

		return nil
	}

	var parts []string
	if len(src.Databases) > 0 {
		parts = append(parts, partDatabases)
	}

	if len(src.MailDirs) > 0 {
		parts = append(parts, partMail)
	}

	if len(src.DocRoots) > 0 {
		parts = append(parts, partFiles)
	}

	manifest, _ := json.Marshal(accountManifest{Version: 1, Username: src.Username, Parts: parts, Created: time.Now().UTC().Format(time.RFC3339)})

	if err := addBytes(tw, manifestName, manifest); err != nil {
		return report, err
	}

	for _, db := range src.Databases {
		info, err := os.Stat(db.Path)
		if err != nil {
			return report, fmt.Errorf("reading the dump of %s: %w", db.Name, err)
		}

		f, err := os.Open(db.Path)
		if err != nil {
			return report, fmt.Errorf("reading the dump of %s: %w", db.Name, err)
		}

		err = addStream(tw, partDatabases+"/"+db.Name+".sql", 0o600, info.Size(), f)
		_ = f.Close()

		if err != nil {
			return report, err
		}

		if err := count(partDatabases, info.Size()); err != nil {
			return report, err
		}
	}

	for _, domain := range sortedKeys(src.MailDirs) {
		if err := addTree(tw, partMail+"/"+domain, src.MailDirs[domain], isMailCacheFile, func(n int64) error { return count(partMail, n) }, &report); err != nil {
			return report, err
		}
	}

	if len(src.DocRoots) > 0 && src.DropPrivileges != nil {
		if err := src.DropPrivileges(); err != nil {
			return report, fmt.Errorf("dropping to the account's own user: %w", err)
		}
	}

	for _, id := range sortedKeys(src.DocRoots) {
		if err := addTree(tw, partFiles+"/"+id, src.DocRoots[id], nil, func(n int64) error { return count(partFiles, n) }, &report); err != nil {
			return report, err
		}
	}

	if err := tw.Close(); err != nil {
		return report, fmt.Errorf("finishing the archive: %w", err)
	}

	if err := gz.Close(); err != nil {
		return report, fmt.Errorf("finishing the archive: %w", err)
	}

	return report, sw.Close()
}

func sortedKeys(m map[string]string) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}

	sort.Strings(keys)

	return keys
}

// isMailCacheFile reports Dovecot's rebuildable index caches, which are not
// worth keeping and are inconsistent with a restored mailbox.
func isMailCacheFile(name string) bool {
	return strings.HasPrefix(name, "dovecot.index") || strings.HasPrefix(name, "dovecot.list.index")
}

func addBytes(tw *tar.Writer, name string, content []byte) error {
	return addStream(tw, name, 0o600, int64(len(content)), strings.NewReader(string(content)))
}

func addStream(tw *tar.Writer, name string, mode int64, size int64, r io.Reader) error {
	if err := tw.WriteHeader(&tar.Header{Name: name, Mode: mode, Size: size, ModTime: time.Now(), Typeflag: tar.TypeReg}); err != nil {
		return fmt.Errorf("writing %s: %w", name, err)
	}

	if _, err := io.CopyN(tw, r, size); err != nil {
		return fmt.Errorf("writing %s: %w", name, err)
	}

	return nil
}

// addTree adds the directory root under prefix: directories, regular files, and
// links that stay inside root. Everything else (sockets, devices, links that
// leave the tree) is left out and listed in report.Skipped. Files are opened
// without following links.
func addTree(tw *tar.Writer, prefix, root string, skip func(name string) bool, count func(int64) error, report *archiveReport) error {
	root = filepath.Clean(root)

	if info, err := os.Lstat(root); err != nil || !info.IsDir() {
		report.Skipped = append(report.Skipped, prefix+": the folder does not exist")

		return nil
	}

	return filepath.WalkDir(root, func(p string, d fs.DirEntry, walkErr error) error {
		if walkErr != nil {
			report.Skipped = append(report.Skipped, prefix+": "+walkErr.Error())

			return nil
		}

		rel, err := filepath.Rel(root, p)
		if err != nil {
			return err
		}

		name := prefix
		if rel != "." {
			name = prefix + "/" + filepath.ToSlash(rel)
		}

		if skip != nil && !d.IsDir() && skip(d.Name()) {
			return nil
		}

		info, err := d.Info()
		if err != nil {
			report.Skipped = append(report.Skipped, name+": "+err.Error())

			return nil
		}

		switch {
		case info.IsDir():
			header, err := tar.FileInfoHeader(info, "")
			if err != nil {
				return err
			}

			header.Name = name + "/"
			header.Uname, header.Gname = "", ""

			return tw.WriteHeader(header)
		case info.Mode()&fs.ModeSymlink != 0:
			target, err := os.Readlink(p)
			if err != nil || !linkStaysInside(rel, target) {
				report.Skipped = append(report.Skipped, name+": a link that leaves the folder")

				return nil
			}

			header, err := tar.FileInfoHeader(info, target)
			if err != nil {
				return err
			}

			header.Name = name
			header.Uname, header.Gname = "", ""

			return tw.WriteHeader(header)
		case info.Mode().IsRegular():
			return addRegular(tw, name, p, count, report)
		default:
			report.Skipped = append(report.Skipped, name+": not a regular file")

			return nil
		}
	})
}

// addRegular copies one file. The size written is the size read from the open
// file, a file that shrinks while being read is zero-filled to keep the archive
// valid, and one that grows is cut at its recorded size.
func addRegular(tw *tar.Writer, name, path string, count func(int64) error, report *archiveReport) error {
	f, err := os.OpenFile(path, os.O_RDONLY|syscall.O_NOFOLLOW, 0)
	if err != nil {
		report.Skipped = append(report.Skipped, name+": "+err.Error())

		return nil
	}
	defer func() { _ = f.Close() }()

	info, err := f.Stat()
	if err != nil || !info.Mode().IsRegular() {
		report.Skipped = append(report.Skipped, name+": not a regular file")

		return nil
	}

	header, err := tar.FileInfoHeader(info, "")
	if err != nil {
		return err
	}

	header.Name = name
	header.Uname, header.Gname = "", ""

	if err := tw.WriteHeader(header); err != nil {
		return fmt.Errorf("writing %s: %w", name, err)
	}

	written, err := io.CopyN(tw, f, header.Size)
	if err != nil && !errors.Is(err, io.EOF) {
		return fmt.Errorf("reading %s: %w", name, err)
	}

	if written < header.Size {
		if _, err := io.CopyN(tw, zeroReader{}, header.Size-written); err != nil {
			return fmt.Errorf("writing %s: %w", name, err)
		}

		report.Skipped = append(report.Skipped, name+": the file changed while it was being backed up")
	}

	return count(header.Size)
}

type zeroReader struct{}

func (zeroReader) Read(p []byte) (int, error) {
	clear(p)

	return len(p), nil
}

// linkStaysInside reports whether a link found at rel (relative to the part
// root) resolves, lexically, to somewhere inside the part root. Absolute
// targets never do.
func linkStaysInside(rel, target string) bool {
	if filepath.IsAbs(target) || target == "" {
		return false
	}

	resolved := path.Clean(path.Join(path.Dir(filepath.ToSlash(rel)), filepath.ToSlash(target)))

	return resolved != ".." && !strings.HasPrefix(resolved, "../")
}

// restoreTarget says what a restore may write and how.
type restoreTarget struct {
	// Parts selects which parts to restore.
	Parts map[string]bool
	// DocRoots and MailDirs map a resource id or mail domain to the live
	// folder to restore into. An archive entry for any other id is ignored.
	DocRoots map[string]string
	MailDirs map[string]string
	// Databases are the database names that may be restored.
	Databases map[string]bool
	// RestoreDatabase receives the dump of one database.
	RestoreDatabase func(name string, sql io.Reader) error
	// DropPrivileges runs once, before the first file of the files part.
	DropPrivileges func() error
	// Chown sets the owner of a restored mail path; nil leaves the owner.
	Chown func(path string, uid, gid int) error
}

// restoreReport says what a restore did.
type restoreReport struct {
	Restored []string `json:"restored"`
	Skipped  []string `json:"skipped,omitempty"`
}

// restoreAccountArchive reads a sealed archive from r and applies it as t says.
// The whole stream must authenticate before the restore counts as done: a
// failure at the end (a truncated or modified archive) is returned even though
// earlier entries were already applied, so the caller reports it.
func restoreAccountArchive(r io.Reader, hexKey string, t restoreTarget) (restoreReport, error) {
	report := restoreReport{}

	sr, err := newSealReader(r, hexKey)
	if err != nil {
		return report, err
	}

	gz, err := gzip.NewReader(sr)
	if err != nil {
		return report, fmt.Errorf("the backup is not readable: %w", err)
	}

	tr := tar.NewReader(gz)
	dropped := false
	touched := map[string]bool{}

	for {
		header, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}

		if err != nil {
			return report, fmt.Errorf("reading the backup: %w", err)
		}

		part, id, rel, ok := splitEntryName(header.Name)
		if !ok {
			report.Skipped = append(report.Skipped, "an entry with an unsafe name was ignored")

			continue
		}

		if part == "" || !t.Parts[part] {
			continue
		}

		switch part {
		case partDatabases:
			name := strings.TrimSuffix(id, ".sql")
			if !t.Databases[name] || t.RestoreDatabase == nil || header.Typeflag != tar.TypeReg {
				report.Skipped = append(report.Skipped, "database "+name+": not restored")

				continue
			}

			if err := t.RestoreDatabase(name, tr); err != nil {
				return report, fmt.Errorf("restoring database %s: %w", name, err)
			}

			touched[partDatabases] = true
		case partMail, partFiles:
			dest := t.MailDirs[id]
			if part == partFiles {
				dest = t.DocRoots[id]
			}

			if dest == "" {
				continue
			}

			if part == partFiles && !dropped {
				if t.DropPrivileges != nil {
					if err := t.DropPrivileges(); err != nil {
						return report, fmt.Errorf("dropping to the account's own user: %w", err)
					}
				}

				dropped = true
			}

			chown := t.Chown
			if part == partFiles {
				chown = nil
			}

			if err := extractEntry(tr, header, dest, rel, chown); err != nil {
				report.Skipped = append(report.Skipped, header.Name+": "+err.Error())

				continue
			}

			touched[part] = true
		}
	}

	// Drain to the end so the final chunk is authenticated.
	if _, err := io.Copy(io.Discard, gz); err != nil {
		return report, fmt.Errorf("the backup is damaged: %w", err)
	}

	for _, part := range []string{partDatabases, partMail, partFiles} {
		if touched[part] {
			report.Restored = append(report.Restored, part)
		}
	}

	return report, nil
}

// splitEntryName splits "files/<id>/a/b" into its part, id and cleaned
// relative path. ok is false for a name that is absolute, climbs out, or is
// otherwise not a plain relative path.
func splitEntryName(name string) (part, id, rel string, ok bool) {
	if name == manifestName {
		return "", "", "", true
	}

	if strings.HasPrefix(name, "/") || strings.ContainsRune(name, 0) || strings.Contains(name, `\`) {
		return "", "", "", false
	}

	for _, segment := range strings.Split(strings.TrimSuffix(name, "/"), "/") {
		if segment == ".." || segment == "" {
			return "", "", "", false
		}
	}

	cleaned := path.Clean(name)

	segments := strings.SplitN(cleaned, "/", 3)
	if len(segments) < 2 {
		return "", "", "", false
	}

	part, id = segments[0], segments[1]

	if part == partDatabases {
		return part, id, "", true
	}

	if part != partFiles && part != partMail {
		return "", "", "", false
	}

	if len(segments) == 3 {
		rel = segments[2]
	}

	return part, id, rel, true
}

// extractEntry writes one directory, file or inside-pointing link below dest.
func extractEntry(tr *tar.Reader, header *tar.Header, dest, rel string, chown func(path string, uid, gid int) error) error {
	target := filepath.Join(dest, filepath.FromSlash(rel))

	if rel != "" && !strings.HasPrefix(target, filepath.Clean(dest)+string(filepath.Separator)) {
		return errors.New("outside the folder")
	}

	mode := fs.FileMode(header.Mode).Perm()

	switch header.Typeflag {
	case tar.TypeDir:
		if err := mkdirNoFollow(target, mode|0o700); err != nil {
			return err
		}

		return setOwner(target, header, chown)
	case tar.TypeReg:
		if err := mkdirNoFollow(filepath.Dir(target), 0o750); err != nil {
			return err
		}

		// A link already at the destination is replaced, never written through.
		if info, err := os.Lstat(target); err == nil && info.Mode()&fs.ModeSymlink != 0 {
			if err := os.Remove(target); err != nil {
				return err
			}
		}

		f, err := os.OpenFile(target, os.O_WRONLY|os.O_CREATE|os.O_TRUNC|syscall.O_NOFOLLOW, mode)
		if err != nil {
			return err
		}

		if _, err := io.Copy(f, tr); err != nil {
			_ = f.Close()

			return err
		}

		if err := f.Close(); err != nil {
			return err
		}

		_ = os.Chtimes(target, time.Now(), header.ModTime)

		return setOwner(target, header, chown)
	case tar.TypeSymlink:
		if !linkStaysInside(rel, header.Linkname) {
			return errors.New("a link that leaves the folder")
		}

		if err := mkdirNoFollow(filepath.Dir(target), 0o750); err != nil {
			return err
		}

		_ = os.Remove(target)

		return os.Symlink(header.Linkname, target)
	}

	return errors.New("not a regular file, folder or link")
}

func setOwner(target string, header *tar.Header, chown func(path string, uid, gid int) error) error {
	if chown == nil {
		return nil
	}

	return chown(target, header.Uid, header.Gid)
}

// mkdirNoFollow creates dir and any missing parents, refusing to pass through
// a link: a link planted in a live site must not redirect a restore.
func mkdirNoFollow(dir string, mode fs.FileMode) error {
	info, err := os.Lstat(dir)
	if err == nil {
		if info.IsDir() {
			return nil
		}

		return errors.New("a link or file is in the way of a folder")
	}

	if !errors.Is(err, fs.ErrNotExist) {
		return err
	}

	if err := mkdirNoFollow(filepath.Dir(dir), 0o750); err != nil {
		return err
	}

	if err := os.Mkdir(dir, mode); err != nil && !errors.Is(err, fs.ErrExist) {
		return err
	}

	return nil
}
