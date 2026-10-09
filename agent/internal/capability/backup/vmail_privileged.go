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
// folder must be a plain domain name and nothing may climb out of it.
func RestoreMailFiles(cfg Config, stdin io.Reader) int {
	if cfg.VMailRoot == "" {
		fmt.Fprintln(os.Stderr, "backup-restore-mail-files: this node has no mail root configured")

		return 1
	}

	files, err := readMailFiles(stdin)
	if err != nil {
		fmt.Fprintln(os.Stderr, "backup-restore-mail-files:", err)

		return 1
	}

	if err := restoreVMail(files, cfg.VMailRoot); err != nil {
		fmt.Fprintln(os.Stderr, "backup-restore-mail-files:", err)

		return 1
	}

	return 0
}

// readMailFiles decodes and validates the mailbox files of a restore request.
func readMailFiles(r io.Reader) ([]vmailFile, error) {
	gz, err := gzip.NewReader(r)
	if err != nil {
		return nil, fmt.Errorf("opening the request: %w", err)
	}

	defer func() { _ = gz.Close() }()

	tr := tar.NewReader(gz)

	var files []vmailFile

	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			return files, nil
		}

		if err != nil {
			return nil, fmt.Errorf("reading the request: %w", err)
		}

		if hdr.Typeflag == tar.TypeDir {
			name := strings.TrimSuffix(hdr.Name, "/")
			if !safeMailFolder(name) {
				return nil, fmt.Errorf("refusing the unsafe path %q", hdr.Name)
			}

			files = append(files, vmailFile{relPath: name, mode: os.FileMode(hdr.Mode).Perm(), uid: hdr.Uid, gid: hdr.Gid, isDir: true})

			continue
		}

		if hdr.Typeflag != tar.TypeReg {
			continue
		}

		if !safeMailPath(hdr.Name) {
			return nil, fmt.Errorf("refusing the unsafe path %q", hdr.Name)
		}

		content, err := io.ReadAll(tr)
		if err != nil {
			return nil, fmt.Errorf("reading %s: %w", hdr.Name, err)
		}

		files = append(files, vmailFile{relPath: hdr.Name, content: content, mode: os.FileMode(hdr.Mode).Perm(), uid: hdr.Uid, gid: hdr.Gid})
	}
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

// archiveMailStatePrivileged runs this binary's "backup-archive-mail-state" mode
// as root and returns its tar.
func archiveMailStatePrivileged(ctx context.Context, sudoBinary, agentBinaryPath string) ([]byte, error) {
	cmd := exec.CommandContext(ctx, sudoBinary, agentBinaryPath, "backup-archive-mail-state")

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return nil, fmt.Errorf("%s: %w: %s", cmd.Path, err, strings.TrimSpace(stderr.String()))
	}

	return stdout.Bytes(), nil
}

// restoreVMailPrivileged hands the mailbox files to the root helper.
func restoreVMailPrivileged(ctx context.Context, sudoBinary, agentBinaryPath string, files []vmailFile) error {
	var request bytes.Buffer

	gz := gzip.NewWriter(&request)
	tw := tar.NewWriter(gz)

	for _, f := range files {
		if f.isDir {
			if err := tw.WriteHeader(&tar.Header{Name: f.relPath + "/", Mode: int64(f.mode), Uid: f.uid, Gid: f.gid, Typeflag: tar.TypeDir}); err != nil {
				return fmt.Errorf("preparing the mail restore: %w", err)
			}

			continue
		}

		hdr := &tar.Header{Name: f.relPath, Mode: int64(f.mode), Size: int64(len(f.content)), Uid: f.uid, Gid: f.gid, Typeflag: tar.TypeReg}
		if err := tw.WriteHeader(hdr); err != nil {
			return fmt.Errorf("preparing the mail restore: %w", err)
		}

		if _, err := tw.Write(f.content); err != nil {
			return fmt.Errorf("preparing the mail restore: %w", err)
		}
	}

	if err := tw.Close(); err != nil {
		return err
	}

	if err := gz.Close(); err != nil {
		return err
	}

	cmd := exec.CommandContext(ctx, sudoBinary, agentBinaryPath, "backup-restore-mail-files")
	cmd.Stdin = &request

	var stderr bytes.Buffer
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return fmt.Errorf("%s: %w: %s", cmd.Path, err, strings.TrimSpace(stderr.String()))
	}

	return nil
}
