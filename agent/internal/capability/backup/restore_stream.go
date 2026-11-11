package backup

import (
	"archive/tar"
	"bufio"
	"bytes"
	"compress/gzip"
	"context"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
)

// A whole-node restore reads the archive as a stream and acts on each entry as it goes: a database
// dump is copied to a private file, a mailbox file is handed on to wherever it is being rebuilt. Only
// when the whole sealed stream has authenticated is anything made live, so a cut-short or tampered
// backup changes nothing on the node.

// mailCommitEntry is the last entry of a request to the root helper. A request without it was cut
// short, and the helper throws away what it staged instead of making it live.
const mailCommitEntry = ".lesta-commit"

// mailSink receives the mailbox entries of a restore as they are read.
type mailSink interface {
	dir(f vmailFile) error
	file(f vmailFile, size int64, content io.Reader) error
	// finish makes the staged mail live when commit is true, and throws it away otherwise.
	finish(commit bool) error
}

// ---- in-process (no root helper) ------------------------------------------------

type localMailSink struct {
	root   string
	stager *vmailStager
}

func (s *localMailSink) ensure() error {
	if s.stager != nil {
		return nil
	}

	st, err := newVMailStager(s.root)
	if err != nil {
		return err
	}

	s.stager = st

	return nil
}

func (s *localMailSink) dir(f vmailFile) error {
	if err := s.ensure(); err != nil {
		return err
	}

	return s.stager.addDir(f)
}

func (s *localMailSink) file(f vmailFile, _ int64, content io.Reader) error {
	if err := s.ensure(); err != nil {
		return err
	}

	return s.stager.addFile(f, content)
}

func (s *localMailSink) finish(commit bool) error {
	if s.stager == nil {
		return nil
	}

	defer s.stager.cleanup()

	if !commit {
		return nil
	}

	return s.stager.commit()
}

// ---- through the root helper -------------------------------------------------------

type privilegedMailSink struct {
	ctx     context.Context
	sudo    string
	agent   string
	cmd     *exec.Cmd
	stdin   io.WriteCloser
	gz      *gzip.Writer
	tw      *tar.Writer
	stderr  bytes.Buffer
	started bool
}

func (s *privilegedMailSink) start() error {
	if s.started {
		return nil
	}

	s.cmd = exec.CommandContext(s.ctx, s.sudo, s.agent, "backup-restore-mail-files")
	s.cmd.Stderr = &s.stderr

	stdin, err := s.cmd.StdinPipe()
	if err != nil {
		return err
	}

	if err := s.cmd.Start(); err != nil {
		return fmt.Errorf("%s: %w", s.cmd.Path, err)
	}

	s.stdin = stdin
	s.gz = gzip.NewWriter(stdin)
	s.tw = tar.NewWriter(s.gz)
	s.started = true

	return nil
}

// fail closes the request and reports why the helper stopped.
func (s *privilegedMailSink) fail(err error) error {
	_ = s.stdin.Close()
	_ = s.cmd.Wait()

	s.started = false

	if msg := strings.TrimSpace(s.stderr.String()); msg != "" {
		return fmt.Errorf("%s: %w: %s", s.cmd.Path, err, msg)
	}

	return err
}

func (s *privilegedMailSink) dir(f vmailFile) error {
	if err := s.start(); err != nil {
		return err
	}

	if err := s.tw.WriteHeader(&tar.Header{Name: f.relPath + "/", Mode: int64(f.mode), Uid: f.uid, Gid: f.gid, Typeflag: tar.TypeDir}); err != nil {
		return s.fail(err)
	}

	return nil
}

func (s *privilegedMailSink) file(f vmailFile, size int64, content io.Reader) error {
	if err := s.start(); err != nil {
		return err
	}

	if err := s.tw.WriteHeader(&tar.Header{Name: f.relPath, Mode: int64(f.mode), Size: size, Uid: f.uid, Gid: f.gid, Typeflag: tar.TypeReg}); err != nil {
		return s.fail(err)
	}

	if _, err := io.Copy(s.tw, io.LimitReader(content, size)); err != nil {
		return s.fail(err)
	}

	return nil
}

func (s *privilegedMailSink) finish(commit bool) error {
	if !s.started {
		return nil
	}

	var err error

	if commit {
		err = s.tw.WriteHeader(&tar.Header{Name: mailCommitEntry, Mode: 0o600, Typeflag: tar.TypeReg})
	}

	if closeErr := s.tw.Close(); err == nil {
		err = closeErr
	}

	if closeErr := s.gz.Close(); err == nil {
		err = closeErr
	}

	_ = s.stdin.Close()

	waitErr := s.cmd.Wait()

	s.started = false

	if !commit {
		return nil
	}

	if err == nil {
		err = waitErr
	}

	if err != nil {
		if msg := strings.TrimSpace(s.stderr.String()); msg != "" {
			return fmt.Errorf("%s: %w: %s", s.cmd.Path, err, msg)
		}

		return err
	}

	return nil
}

// ---- the restore ----------------------------------------------------------------------

// restoreFailure is a failed restore with the code the operation reports.
type restoreFailure struct {
	code string
	err  error
}

func (f *restoreFailure) Error() string { return f.err.Error() }

func newRestoreFailure(code string, err error) *restoreFailure {
	return &restoreFailure{code: code, err: err}
}

// restoreCapabilities is the order the database dumps are put back in.
var restoreCapabilities = []string{databaseControlPlaneCapability, databaseTenantCapability}

// restoreFromStream acts on the plaintext archive (a gzip-compressed tar) read from plain.
func (c *BackupCapability) restoreFromStream(ctx context.Context, plain io.Reader) ([]string, *restoreFailure) {
	gz, err := gzip.NewReader(plain)
	if err != nil {
		return nil, newRestoreFailure("archive_read_failed", fmt.Errorf("opening gzip stream: %w", err))
	}

	defer func() { _ = gz.Close() }()

	if err := os.MkdirAll(c.cfg.ArtifactsRoot, 0o750); err != nil {
		return nil, newRestoreFailure("artifacts_root_unavailable", err)
	}

	workDir, err := os.MkdirTemp(c.cfg.ArtifactsRoot, ".restore-*")
	if err != nil {
		return nil, newRestoreFailure("artifacts_root_unavailable", err)
	}

	defer func() { _ = os.RemoveAll(workDir) }()

	var sink mailSink

	abort := func(f *restoreFailure) ([]string, *restoreFailure) {
		if sink != nil {
			_ = sink.finish(false)
		}

		return nil, f
	}

	tr := tar.NewReader(gz)
	dumps := make(map[string]string)

	for {
		hdr, err := tr.Next()
		if errors.Is(err, io.EOF) {
			break
		}

		if err != nil {
			return abort(newRestoreFailure("archive_read_failed", fmt.Errorf("reading tar entry: %w", err)))
		}

		isMail := strings.HasPrefix(hdr.Name, vmailPrefix)

		if isMail && hdr.Typeflag == tar.TypeDir {
			rel := strings.TrimSuffix(strings.TrimPrefix(hdr.Name, vmailPrefix), "/")
			if rel == "" {
				continue
			}

			if !safeMailFolder(rel) {
				return abort(newRestoreFailure("archive_read_failed", fmt.Errorf("refusing the unsafe path %q", hdr.Name)))
			}

			if sink == nil {
				s, failure := c.newMailSink(ctx)
				if failure != nil {
					return abort(failure)
				}

				sink = s
			}

			if err := sink.dir(vmailFile{relPath: rel, mode: os.FileMode(hdr.Mode).Perm(), uid: hdr.Uid, gid: hdr.Gid, isDir: true}); err != nil {
				return abort(newRestoreFailure("vmail_restore_failed", err))
			}

			continue
		}

		if hdr.Typeflag != tar.TypeReg {
			continue
		}

		switch {
		case strings.HasSuffix(hdr.Name, "/dump.sql"):
			capability := strings.TrimSuffix(hdr.Name, "/dump.sql")
			if capability != databaseControlPlaneCapability && capability != databaseTenantCapability {
				continue
			}

			path := filepath.Join(workDir, capability+".sql")
			if err := copyToFile(path, tr, hdr.Size); err != nil {
				return abort(newRestoreFailure("archive_read_failed", fmt.Errorf("reading %s: %w", hdr.Name, err)))
			}

			dumps[capability] = path
		case isMail:
			rel := strings.TrimPrefix(hdr.Name, vmailPrefix)
			if !safeMailPath(rel) {
				return abort(newRestoreFailure("archive_read_failed", fmt.Errorf("refusing the unsafe path %q", hdr.Name)))
			}

			if sink == nil {
				s, failure := c.newMailSink(ctx)
				if failure != nil {
					return abort(failure)
				}

				sink = s
			}

			if err := sink.file(vmailFile{relPath: rel, mode: os.FileMode(hdr.Mode).Perm(), uid: hdr.Uid, gid: hdr.Gid}, hdr.Size, tr); err != nil {
				return abort(newRestoreFailure("vmail_restore_failed", err))
			}
		}
	}

	// The tar ended; now the gzip trailer and the last sealed chunk must check out too, or the
	// backup is not whole and nothing is made live.
	if _, err := io.Copy(io.Discard, gz); err != nil {
		return abort(newRestoreFailure("archive_read_failed", fmt.Errorf("reading the archive's end: %w", err)))
	}

	if _, err := io.Copy(io.Discard, plain); err != nil {
		return abort(newRestoreFailure("archive_read_failed", fmt.Errorf("reading the archive's end: %w", err)))
	}

	var restored []string

	if sink != nil {
		if err := sink.finish(true); err != nil {
			return nil, newRestoreFailure("vmail_restore_failed", err)
		}

		restored = append(restored, mailCapability)
	}

	for _, capability := range restoreCapabilities {
		path, ok := dumps[capability]
		if !ok {
			continue
		}

		socket, ok := c.cfg.DatabaseDumpSockets[capability]
		if !ok {
			continue
		}

		if _, err := os.Stat(socket); err != nil {
			continue
		}

		f, err := os.Open(path)
		if err != nil {
			return nil, newRestoreFailure("database_restore_failed", fmt.Errorf("%s: %w", capability, err))
		}

		err = restoreSocketFrom(ctx, c.cfg.SudoBinary, socket, c.cfg.DatabaseRestoreDefaults[capability], f)

		_ = f.Close()

		if err != nil {
			return nil, newRestoreFailure("database_restore_failed", fmt.Errorf("%s: %w", capability, err))
		}

		restored = append(restored, capability)
	}

	sort.Strings(restored)

	return restored, nil
}

func (c *BackupCapability) newMailSink(ctx context.Context) (mailSink, *restoreFailure) {
	if c.cfg.VMailRoot == "" {
		return nil, newRestoreFailure("vmail_root_not_configured", errors.New("this node's own config has no VMailRoot set; refusing to restore mail content into an unknown location"))
	}

	if c.cfg.SudoBinary != "" && c.cfg.AgentBinaryPath != "" {
		// The mailboxes are owned by the mail user: restoring them (and their ownership) needs the root helper.
		return &privilegedMailSink{ctx: ctx, sudo: c.cfg.SudoBinary, agent: c.cfg.AgentBinaryPath}, nil
	}

	return &localMailSink{root: c.cfg.VMailRoot}, nil
}

// copyToFile writes exactly size bytes of r to a new private file at path.
func copyToFile(path string, r io.Reader, size int64) error {
	f, err := os.OpenFile(path, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, 0o600)
	if err != nil {
		return err
	}

	n, err := io.Copy(f, io.LimitReader(r, size))

	if closeErr := f.Close(); err == nil {
		err = closeErr
	}

	if err == nil && n != size {
		err = io.ErrUnexpectedEOF
	}

	return err
}

// verifiedReader reads from r after proving its first chunk authenticates, so a wrong key is reported
// as a decryption failure up front instead of as a broken archive halfway through.
func verifiedReader(r io.Reader) (io.Reader, error) {
	br := bufio.NewReaderSize(r, streamChunkSize)

	if _, err := br.Peek(1); err != nil && !errors.Is(err, io.EOF) {
		return nil, err
	}

	return br, nil
}
