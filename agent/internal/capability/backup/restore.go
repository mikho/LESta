package backup

import (
	"bytes"
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// mailCapability mirrors dump.go's own established pattern of locally
// duplicating a capability name string constant rather than importing it
// from cmd/lesta-agent/main.go: this package's own Config is fed these
// strings as plain map keys, never a shared exported constant.
const mailCapability = "mail.smtp-imap.v1"

// vmailPrefix is the tar path prefix archiveStateRoots writes real maildir
// content under (mailCapability's own StateRoot includes Config.VMailRoot
// as a real subdirectory -- see .install/services/mail/install.sh's own
// VMAIL_HOME, a child of MAIL_STATE_ROOT).
const vmailPrefix = mailCapability + "/vmail/"

// restoredData is the shape reported on a successful restore's own
// ResultEnvelope.Data.
type restoredData struct {
	RestoredCapabilities []string `json:"restored_capabilities"`
}

// vmailFile is one real file this node's own decrypted archive carries
// under vmailPrefix, keyed by its path relative to the vmail root itself
// (e.g. "example.com/sales/cur/1700000000.M123.host,S=456").
type vmailFile struct {
	relPath string
	content []byte
	mode    os.FileMode
	uid     int
	gid     int
	// isDir marks a folder entry: its own owner and mode are restored too,
	// otherwise a folder made on the way to a file would belong to whoever runs
	// the restore (root) and the mail user could not enter it.
	isDir bool
}

// applyRestore decrypts this node's own already-local sealed artifact and
// puts back the two things no other capability's own generation history can
// ever regenerate: mail's real maildir content (Config.VMailRoot) and each
// database capability's own real mysqldump output (Config.DatabaseDumpSockets).
// Everything else a backup archive also contains -- nginx/apache/bind9/cron/
// mail's own rendered config -- is deliberately left untouched here: it is
// always fully, losslessly re-derivable from Laravel's own database via a
// normal update, so replaying old generation content for it would only add
// risk for zero benefit. Laravel's own ResyncNode is what brings the rest of
// the node current, once this operation's own result comes back (see
// CascadesRestoreIntoResync.php).
func (c *BackupCapability) applyRestore(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParseRestorePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(op, verr)
	}

	cleaned := filepath.Clean(*payload.ArtifactPath)
	if !isWithinRoot(cleaned, c.cfg.ArtifactsRoot) {
		return c.rejected(op, "artifact_path_outside_root",
			fmt.Sprintf("artifact_path %q is not within the owned artifacts root %q", *payload.ArtifactPath, c.cfg.ArtifactsRoot), "artifact_path")
	}

	if payload.Account != nil {
		return c.applyAccountRestoreOp(ctx, op, payload)
	}

	plain, closeArtifact, err := readableNodeArtifact(cleaned, *payload.EncryptionKey, maxNodeArchiveBytes)
	if os.IsNotExist(err) {
		return c.rejected(op, "artifact_not_found", fmt.Sprintf("no artifact exists at %q", *payload.ArtifactPath), "artifact_path")
	} else if errors.Is(err, errNodeBackupTooLarge) {
		return c.failed(op, "artifact_too_large", err.Error())
	} else if err != nil {
		return c.failed(op, "artifact_read_failed", err.Error())
	}

	defer closeArtifact()

	verified, err := verifiedReader(plain)
	if err != nil {
		return c.failed(op, "decryption_failed", err.Error())
	}

	restored, failure := c.restoreFromStream(ctx, verified)
	if failure != nil {
		return c.failed(op, failure.code, failure.Error())
	}

	data, err := json.Marshal(restoredData{RestoredCapabilities: restored})
	if err != nil {
		// restoredData is a fixed, always-marshalable shape; this cannot
		// realistically fail, but silently dropping the one thing this
		// operation exists to report would be worse than a loud panic.
		panic(fmt.Sprintf("marshaling backup restore data: %v", err))
	}

	result := c.buildResult(op, protocol.StatusApplied, nil)
	result.Data = data

	return result, nil
}

// restoreVMail writes files into a staging directory first, then swaps each
// top-level domain directory it found into place under liveVMailRoot one at
// a time: never a blind in-place overwrite of real, currently-live mail.
// Ownership (uid/gid) is restored from the tar header's own recorded
// numeric values, correct for restoring to the same node the backup was
// taken on (this feature's own disclosed scope boundary -- restoring to a
// different node isn't supported, see the vault decision log for why).
// Mail is never root's and never world-accessible, so an entry owned by root
// is refused and only the owner and group bits of a mode are kept.
func restoreVMail(files []vmailFile, liveVMailRoot string) error {
	for _, f := range files {
		if f.uid == 0 || f.gid == 0 {
			return fmt.Errorf("refusing %s: mail is never owned by root", f.relPath)
		}
	}

	stager, err := newVMailStager(liveVMailRoot)
	if err != nil {
		return err
	}

	defer stager.cleanup()

	// Folders first (shortest path first), each with its own recorded owner and
	// mode; a folder a file needs that has no entry of its own is made with that
	// file's owner.
	sort.SliceStable(files, func(i, j int) bool { return files[i].isDir && !files[j].isDir })

	for _, f := range files {
		if f.isDir {
			err = stager.addDir(f)
		} else {
			err = stager.addFile(f, bytes.NewReader(f.content))
		}

		if err != nil {
			return err
		}
	}

	return stager.commit()
}

// vmailStager builds restored mail in a staging directory beside the live mail root and swaps each
// domain folder into place on commit. Entries may arrive one at a time, in any order that has a folder
// before what is inside it.
type vmailStager struct {
	staging string
	live    string
	domains map[string]struct{}
}

func newVMailStager(liveVMailRoot string) (*vmailStager, error) {
	staging, err := os.MkdirTemp(filepath.Dir(liveVMailRoot), "vmail-restore-*")
	if err != nil {
		return nil, fmt.Errorf("creating staging directory: %w", err)
	}

	return &vmailStager{staging: staging, live: liveVMailRoot, domains: make(map[string]struct{})}, nil
}

func (s *vmailStager) cleanup() {
	_ = os.RemoveAll(s.staging)
}

func (s *vmailStager) addDir(f vmailFile) error {
	if f.uid == 0 || f.gid == 0 {
		return fmt.Errorf("refusing %s: mail is never owned by root", f.relPath)
	}

	domain, _, _ := strings.Cut(f.relPath, "/")
	s.domains[domain] = struct{}{}

	dest := filepath.Join(s.staging, f.relPath)

	if err := os.MkdirAll(dest, 0o750); err != nil {
		return fmt.Errorf("creating %s: %w", dest, err)
	}

	if err := os.Chown(dest, f.uid, f.gid); err != nil {
		return fmt.Errorf("restoring ownership of %s: %w", dest, err)
	}

	if err := os.Chmod(dest, f.mode&0o770); err != nil {
		return fmt.Errorf("restoring the mode of %s: %w", dest, err)
	}

	return nil
}

func (s *vmailStager) addFile(f vmailFile, content io.Reader) error {
	if f.uid == 0 || f.gid == 0 {
		return fmt.Errorf("refusing %s: mail is never owned by root", f.relPath)
	}

	domain, _, hasRest := strings.Cut(f.relPath, "/")
	if !hasRest {
		// A file straight under the mail root belongs to no domain.
		return nil
	}

	s.domains[domain] = struct{}{}

	dest := filepath.Join(s.staging, f.relPath)

	if err := mkdirAllOwned(filepath.Dir(dest), s.staging, f.uid, f.gid); err != nil {
		return err
	}

	out, err := os.OpenFile(dest, os.O_WRONLY|os.O_CREATE|os.O_TRUNC, f.mode&0o660)
	if err != nil {
		return fmt.Errorf("writing %s: %w", dest, err)
	}

	_, err = io.Copy(out, content)

	if closeErr := out.Close(); err == nil {
		err = closeErr
	}

	if err != nil {
		return fmt.Errorf("writing %s: %w", dest, err)
	}

	if err := os.Chmod(dest, f.mode&0o660); err != nil {
		return fmt.Errorf("restoring the mode of %s: %w", dest, err)
	}

	if err := os.Chown(dest, f.uid, f.gid); err != nil {
		return fmt.Errorf("restoring ownership of %s: %w", dest, err)
	}

	return nil
}

func (s *vmailStager) commit() error {
	for domain := range s.domains {
		stagedDomainDir := filepath.Join(s.staging, domain)
		liveDomainDir := filepath.Join(s.live, domain)

		if err := os.MkdirAll(s.live, 0o750); err != nil {
			return fmt.Errorf("creating %s: %w", s.live, err)
		}

		asideDir := liveDomainDir + ".pre-restore"
		_ = os.RemoveAll(asideDir)

		if _, err := os.Stat(liveDomainDir); err == nil {
			if err := os.Rename(liveDomainDir, asideDir); err != nil {
				return fmt.Errorf("moving current %s aside before restoring: %w", liveDomainDir, err)
			}
		}

		if err := os.Rename(stagedDomainDir, liveDomainDir); err != nil {
			// Put the current mail back: left aside, the next restore would
			// delete it.
			if _, statErr := os.Stat(asideDir); statErr == nil {
				_ = os.Rename(asideDir, liveDomainDir)
			}

			return fmt.Errorf("activating restored %s: %w", liveDomainDir, err)
		}

		_ = os.RemoveAll(asideDir)
	}

	return nil
}

// mariadbClientBinary mirrors mariadbDumpBinary's own resolution order for
// the client binary rather than the dump binary: mariadb (the real binary
// MariaDB Foundation's own mariadb-client package ships from 10.5 onward),
// falling back to mysql.
func mariadbClientBinary() string {
	if path, err := exec.LookPath("mariadb"); err == nil {
		return path
	}

	return "mysql"
}

// restoreSocket pipes dumpSQL into a live MariaDB instance over its own real
// unix socket, root-authenticated via unix_socket auth -- the exact reverse
// of dumpSocket's own connection shape. mysqldump's own default output uses
// "DROP TABLE IF EXISTS" per table, never "DROP DATABASE", so restoring a
// whole-instance dump like this overwrites whatever tables it describes
// with their own backup-time content, but never touches a database/table
// that didn't exist in the dump at all (e.g. one created after the backup
// was taken) -- a real, disclosed restore semantic, not "make live state
// exactly match the backup."
//
// sudoBinary: see dumpSocket's own doc comment on why unix_socket auth
// needs this for the real unprivileged lesta-agent-daemon.
//
// defaultsFile, when set, is the option file of the restore account for this instance, which logs
// in instead of root. It may not be narrower than it is: the dump rewrites the system schema.
func restoreSocket(ctx context.Context, sudoBinary, socketPath, defaultsFile string, dumpSQL []byte) error {
	return restoreSocketFrom(ctx, sudoBinary, socketPath, defaultsFile, bytes.NewReader(dumpSQL))
}

// restoreSocketFrom is restoreSocket over a stream, so a large dump is never held in memory.
func restoreSocketFrom(ctx context.Context, sudoBinary, socketPath, defaultsFile string, dump io.Reader) error {
	args := authArgs(defaultsFile, "--socket="+socketPath)

	var cmd *exec.Cmd
	if sudoBinary == "" {
		cmd = exec.CommandContext(ctx, mariadbClientBinary(), args...)
	} else {
		cmd = exec.CommandContext(ctx, sudoBinary, append([]string{mariadbClientBinary()}, args...)...)
	}

	cmd.Stdin = dump

	var stderr bytes.Buffer
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return fmt.Errorf("%s: %w: %s", cmd.Path, err, strings.TrimSpace(stderr.String()))
	}

	return nil
}

// mkdirAllOwned creates dir and any missing parents below stop, giving each the
// owner given, so a folder made only because a file needed it is not left owned
// by the user running the restore.
func mkdirAllOwned(dir, stop string, uid, gid int) error {
	if dir == stop || dir == "." || dir == string(filepath.Separator) {
		return nil
	}

	if info, err := os.Stat(dir); err == nil && info.IsDir() {
		return nil
	}

	if err := mkdirAllOwned(filepath.Dir(dir), stop, uid, gid); err != nil {
		return err
	}

	if err := os.Mkdir(dir, 0o750); err != nil && !os.IsExist(err) {
		return fmt.Errorf("creating %s: %w", dir, err)
	}

	if err := os.Chown(dir, uid, gid); err != nil {
		return fmt.Errorf("restoring ownership of %s: %w", dir, err)
	}

	return nil
}
