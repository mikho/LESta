package backup

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path"
	"path/filepath"
	"strings"
)

// The mail state root holds the virtual mailboxes (<root>/vmail/<domain>/<user>/...),
// owned by the mail user and not readable or writable by the unprivileged
// lesta-agent-daemon, so a whole-node backup and restore of mail run through two
// small root-only CLI modes of this binary, the same pattern as cron's
// "cron-archive-state". Both are scoped by the backups installer's sudoers rule.

// ArchiveMailState is the "backup-archive-mail-state" CLI mode: it writes a
// gzip-compressed tar of the mail state root, with entry names relative to the
// root, to w, and returns a process exit code. It only reads.
func ArchiveMailState(cfg Config, w io.Writer) int {
	root := cfg.StateRoots[mailCapability]
	if root == "" {
		fmt.Fprintln(os.Stderr, "backup-archive-mail-state: this node has no mail state root configured")

		return 1
	}

	if err := archiveDirectoryRelative(root, w); err != nil {
		fmt.Fprintln(os.Stderr, "backup-archive-mail-state:", err)

		return 1
	}

	return 0
}

// archiveDirectoryRelative writes every directory and regular file under root to
// w as a gzip-compressed tar, named relative to root.
func archiveDirectoryRelative(root string, w io.Writer) error {
	gz := gzip.NewWriter(w)
	tw := tar.NewWriter(gz)

	err := filepath.Walk(root, func(p string, info os.FileInfo, err error) error {
		if err != nil {
			return err
		}

		rel, err := filepath.Rel(root, p)
		if err != nil || rel == "." {
			return err
		}

		hdr, err := tar.FileInfoHeader(info, "")
		if err != nil {
			return err
		}

		hdr.Name = filepath.ToSlash(rel)

		switch {
		case info.IsDir():
			hdr.Name += "/"

			return tw.WriteHeader(hdr)
		case info.Mode().IsRegular():
			if err := tw.WriteHeader(hdr); err != nil {
				return err
			}

			f, err := os.Open(p)
			if err != nil {
				return err
			}
			defer func() { _ = f.Close() }()

			_, err = io.Copy(tw, f)

			return err
		}

		return nil
	})
	if err != nil {
		return fmt.Errorf("archiving %s: %w", root, err)
	}

	if err := tw.Close(); err != nil {
		return err
	}

	return gz.Close()
}

// RestoreMailFiles is the "backup-restore-mail-files" CLI mode: it reads a
// gzip-compressed tar of mailbox files from stdin, named "<domain>/<user>/...",
// and puts them back under the mail root (domain folder by domain folder, never
// an in-place overwrite, see restoreVMail). Every name is checked: a domain
// folder must be a plain domain name and nothing may climb out of it. The request
// is acted on as it arrives, but only made live if it ends with mailCommitEntry.
func RestoreMailFiles(cfg Config, stdin io.Reader) int {
	if cfg.VMailRoot == "" {
		fmt.Fprintln(os.Stderr, "backup-restore-mail-files: this node has no mail root configured")

		return 1
	}

	if err := restoreMailStream(stdin, cfg.VMailRoot); err != nil {
		fmt.Fprintln(os.Stderr, "backup-restore-mail-files:", err)

		return 1
	}

	return 0
}

// restoreMailStream stages the mailbox files of a request as they are read and makes them live only
// when the request is whole.
func restoreMailStream(r io.Reader, liveRoot string) error {
	gz, err := gzip.NewReader(r)
	if err != nil {
		return fmt.Errorf("opening the request: %w", err)
	}

	defer func() { _ = gz.Close() }()

	stager, err := newVMailStager(liveRoot)
	if err != nil {
		return err
	}

	defer stager.cleanup()

	tr := tar.NewReader(gz)
	committed := false

	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}

		if err != nil {
			return fmt.Errorf("reading the request: %w", err)
		}

		if hdr.Typeflag == tar.TypeDir {
			name := strings.TrimSuffix(hdr.Name, "/")
			if !safeMailFolder(name) {
				return fmt.Errorf("refusing the unsafe path %q", hdr.Name)
			}

			if err := stager.addDir(vmailFile{relPath: name, mode: os.FileMode(hdr.Mode).Perm(), uid: hdr.Uid, gid: hdr.Gid, isDir: true}); err != nil {
				return err
			}

			continue
		}

		if hdr.Typeflag != tar.TypeReg {
			continue
		}

		if hdr.Name == mailCommitEntry {
			committed = true

			continue
		}

		if !safeMailPath(hdr.Name) {
			return fmt.Errorf("refusing the unsafe path %q", hdr.Name)
		}

		if err := stager.addFile(vmailFile{relPath: hdr.Name, mode: os.FileMode(hdr.Mode).Perm(), uid: hdr.Uid, gid: hdr.Gid}, io.LimitReader(tr, hdr.Size)); err != nil {
			return err
		}
	}

	if _, err := io.Copy(io.Discard, gz); err != nil {
		return fmt.Errorf("reading the request: %w", err)
	}

	if !committed {
		return errors.New("the request was cut short; nothing was restored")
	}

	return stager.commit()
}

// safeMailFolder is safeMailPath for a folder, which may be the domain folder itself.
func safeMailFolder(name string) bool {
	if !strings.Contains(name, "/") {
		return accountDomainPattern.MatchString(name)
	}

	return safeMailPath(name)
}

// safeMailPath reports whether name is "<domain>/<rest>" with a plain domain
// folder and a rest that stays below it.
func safeMailPath(name string) bool {
	if name == "" || strings.HasPrefix(name, "/") || strings.ContainsRune(name, 0) || strings.Contains(name, `\`) || path.Clean(name) != name {
		return false
	}

	domain, rest, ok := strings.Cut(name, "/")
	if !ok || rest == "" || !accountDomainPattern.MatchString(domain) {
		return false
	}

	for _, segment := range strings.Split(rest, "/") {
		if segment == "" || segment == ".." || segment == "." {
			return false
		}
	}

	return true
}

// ---- daemon side ---------------------------------------------------------

// streamMailStatePrivileged runs this binary's "backup-archive-mail-state" mode as root and copies the
// tar it writes into tw as it arrives, under prefix.
func streamMailStatePrivileged(ctx context.Context, sudoBinary, agentBinaryPath string, tw *tar.Writer, prefix string) error {
	cmd := exec.CommandContext(ctx, sudoBinary, agentBinaryPath, "backup-archive-mail-state")

	var stderr bytes.Buffer
	cmd.Stderr = &stderr

	stdout, err := cmd.StdoutPipe()
	if err != nil {
		return err
	}

	if err := cmd.Start(); err != nil {
		return fmt.Errorf("%s: %w", cmd.Path, err)
	}

	mergeErr := mergeNestedStream(tw, stdout, prefix)

	// Drain what the merge did not read, so the helper is never left blocked on a full pipe.
	_, _ = io.Copy(io.Discard, stdout)

	if err := cmd.Wait(); err != nil {
		return fmt.Errorf("%s: %w: %s", cmd.Path, err, strings.TrimSpace(stderr.String()))
	}

	return mergeErr
}
