package backup

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"encoding/json"
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

	sealed, err := os.ReadFile(cleaned)
	if os.IsNotExist(err) {
		return c.rejected(op, "artifact_not_found", fmt.Sprintf("no artifact exists at %q", *payload.ArtifactPath), "artifact_path")
	} else if err != nil {
		return c.failed(op, "artifact_read_failed", err.Error())
	}

	plaintext, err := Decrypt(*payload.EncryptionKey, sealed)
	if err != nil {
		return c.failed(op, "decryption_failed", err.Error())
	}

	dumpsByCapability, vmailFiles, err := extractRestorable(plaintext)
	if err != nil {
		return c.failed(op, "archive_read_failed", err.Error())
	}

	var restored []string

	if len(vmailFiles) > 0 {
		if c.cfg.VMailRoot == "" {
			return c.failed(op, "vmail_root_not_configured", "this node's own config has no VMailRoot set; refusing to restore mail content into an unknown location")
		}

		if err := restoreVMail(vmailFiles, c.cfg.VMailRoot); err != nil {
			return c.failed(op, "vmail_restore_failed", err.Error())
		}

		restored = append(restored, mailCapability)
	}

	for capability, sql := range dumpsByCapability {
		socket, ok := c.cfg.DatabaseDumpSockets[capability]
		if !ok {
			continue
		}

		if _, err := os.Stat(socket); err != nil {
			continue
		}

		if err := restoreSocket(ctx, socket, sql); err != nil {
			return c.failed(op, "database_restore_failed", fmt.Sprintf("%s: %s", capability, err.Error()))
		}

		restored = append(restored, capability)
	}

	sort.Strings(restored)

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

// extractRestorable reads plaintext (a decrypted, gzip-compressed tar
// stream) exactly once, picking out only the two entry shapes restore ever
// acts on: a database capability's own "<capability>/dump.sql" and mail's
// own real maildir files under vmailPrefix. Every other entry (nginx/apache/
// bind9/cron/mail's own rendered config) is silently skipped -- restore has
// no use for it at all, per this file's own top doc comment.
func extractRestorable(plaintext []byte) (map[string][]byte, []vmailFile, error) {
	gz, err := gzip.NewReader(bytes.NewReader(plaintext))
	if err != nil {
		return nil, nil, fmt.Errorf("opening gzip stream: %w", err)
	}
	defer gz.Close()

	tr := tar.NewReader(gz)

	dumps := make(map[string][]byte)
	var vmailFiles []vmailFile

	for {
		hdr, err := tr.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			return nil, nil, fmt.Errorf("reading tar entry: %w", err)
		}

		if hdr.Typeflag != tar.TypeReg {
			continue
		}

		switch {
		case strings.HasSuffix(hdr.Name, "/dump.sql"):
			capability := strings.TrimSuffix(hdr.Name, "/dump.sql")

			content, err := io.ReadAll(tr)
			if err != nil {
				return nil, nil, fmt.Errorf("reading %s: %w", hdr.Name, err)
			}

			dumps[capability] = content
		case strings.HasPrefix(hdr.Name, vmailPrefix):
			content, err := io.ReadAll(tr)
			if err != nil {
				return nil, nil, fmt.Errorf("reading %s: %w", hdr.Name, err)
			}

			vmailFiles = append(vmailFiles, vmailFile{
				relPath: strings.TrimPrefix(hdr.Name, vmailPrefix),
				content: content,
				mode:    os.FileMode(hdr.Mode),
				uid:     hdr.Uid,
				gid:     hdr.Gid,
			})
		}
	}

	return dumps, vmailFiles, nil
}

// restoreVMail writes files into a staging directory first, then swaps each
// top-level domain directory it found into place under liveVMailRoot one at
// a time: never a blind in-place overwrite of real, currently-live mail.
// Ownership (uid/gid) is restored from the tar header's own recorded
// numeric values, correct for restoring to the same node the backup was
// taken on (this feature's own disclosed scope boundary -- restoring to a
// different node isn't supported, see the vault decision log for why).
func restoreVMail(files []vmailFile, liveVMailRoot string) error {
	staging, err := os.MkdirTemp(filepath.Dir(liveVMailRoot), "vmail-restore-*")
	if err != nil {
		return fmt.Errorf("creating staging directory: %w", err)
	}
	defer os.RemoveAll(staging)

	domains := make(map[string]struct{})

	for _, f := range files {
		domain, _, ok := strings.Cut(f.relPath, "/")
		if !ok {
			continue
		}
		domains[domain] = struct{}{}

		dest := filepath.Join(staging, f.relPath)
		if err := os.MkdirAll(filepath.Dir(dest), 0o750); err != nil {
			return fmt.Errorf("creating %s: %w", filepath.Dir(dest), err)
		}

		if err := os.WriteFile(dest, f.content, f.mode); err != nil {
			return fmt.Errorf("writing %s: %w", dest, err)
		}

		if err := os.Chown(dest, f.uid, f.gid); err != nil {
			return fmt.Errorf("restoring ownership of %s: %w", dest, err)
		}
	}

	for domain := range domains {
		stagedDomainDir := filepath.Join(staging, domain)
		liveDomainDir := filepath.Join(liveVMailRoot, domain)

		if err := os.MkdirAll(liveVMailRoot, 0o750); err != nil {
			return fmt.Errorf("creating %s: %w", liveVMailRoot, err)
		}

		asideDir := liveDomainDir + ".pre-restore"
		_ = os.RemoveAll(asideDir)

		if _, err := os.Stat(liveDomainDir); err == nil {
			if err := os.Rename(liveDomainDir, asideDir); err != nil {
				return fmt.Errorf("moving current %s aside before restoring: %w", liveDomainDir, err)
			}
		}

		if err := os.Rename(stagedDomainDir, liveDomainDir); err != nil {
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
func restoreSocket(ctx context.Context, socketPath string, dumpSQL []byte) error {
	cmd := exec.CommandContext(ctx, mariadbClientBinary(), "--socket="+socketPath, "-u", "root")
	cmd.Stdin = bytes.NewReader(dumpSQL)

	var stderr bytes.Buffer
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return fmt.Errorf("%s: %w: %s", cmd.Path, err, strings.TrimSpace(stderr.String()))
	}

	return nil
}
