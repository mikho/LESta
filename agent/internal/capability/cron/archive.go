package cron

import (
	"archive/tar"
	"compress/gzip"
	"fmt"
	"io"
	"os"
	"path/filepath"
)

// ArchiveState tars+gzips cfg.StateRoot in its entirety, writing the result
// to w, and returns a process exit code. This CLI mode (see
// cmd/lesta-agent/main.go's own "cron-archive-state" dispatch) exists for
// exactly one caller: backup.encrypted-artifacts.v1's own archive step,
// invoked via sudo (backup.Config's own SudoBinary/AgentBinaryPath) because
// StateRoot/accounts/<run_as> is deliberately root:<run_as> mode 2750 --
// readable only by root and that account's own dedicated Linux user, never
// the shared lesta group the real unprivileged lesta-agent-daemon runs as
// (see ensureAccountDir's own doc comment on why). This is a narrow,
// deliberate exception to that isolation, scoped to exactly this one
// read-only archiving action: it never writes anything, and its output only
// ever flows into an encrypted backup artifact under
// backup.encrypted-artifacts.v1's own owned ArtifactsRoot (itself readable
// only by root and lesta).
func ArchiveState(cfg Config, w io.Writer) int {
	if err := archiveDirectory(cfg.StateRoot, w); err != nil {
		fmt.Fprintln(os.Stderr, "cron-archive-state:", err)

		return 1
	}

	return 0
}

// archiveDirectory writes a gzip-compressed tar stream of every regular
// file and directory under root to w, with entry names relative to root
// itself (e.g. "accounts/lesta-cron/jobs/sidecar/<id>.json") -- deliberately
// not prefixed with root's own path, since the caller on the other end of
// this stream (backup.encrypted-artifacts.v1's own archive step) re-prefixes
// every entry with this capability's own protocol name.
func archiveDirectory(root string, w io.Writer) error {
	gz := gzip.NewWriter(w)
	tw := tar.NewWriter(gz)

	walkErr := filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			return err
		}

		rel, err := filepath.Rel(root, path)
		if err != nil {
			return err
		}

		if rel == "." {
			return nil
		}

		hdr, err := tar.FileInfoHeader(info, "")
		if err != nil {
			return err
		}
		hdr.Name = filepath.ToSlash(rel)

		if info.IsDir() {
			hdr.Name += "/"

			return tw.WriteHeader(hdr)
		}

		if !info.Mode().IsRegular() {
			return nil
		}

		if err := tw.WriteHeader(hdr); err != nil {
			return err
		}

		f, err := os.Open(path)
		if err != nil {
			return err
		}
		defer f.Close()

		_, err = io.Copy(tw, f)

		return err
	})
	if walkErr != nil {
		return fmt.Errorf("archiving %s: %w", root, walkErr)
	}

	if err := tw.Close(); err != nil {
		return fmt.Errorf("closing tar writer: %w", err)
	}

	return gz.Close()
}
