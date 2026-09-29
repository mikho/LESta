package backup

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"fmt"
	"io"
	"os"
	"os/exec"
	"path/filepath"
	"sort"
	"strings"
	"time"
)

// schedulerCronCapability mirrors mailCapability's own established pattern
// (restore.go) of locally duplicating a capability name string constant
// rather than importing it from cmd/lesta-agent/main.go.
const schedulerCronCapability = "scheduler.account-cron.v1"

// discoverIncludedCapabilities returns the sorted list of capability names
// whose StateRoots entry exists on disk as a real, non-empty directory. A
// missing or empty root means that capability simply isn't active on this
// node (or has never rendered anything yet), not an error.
func discoverIncludedCapabilities(stateRoots map[string]string) []string {
	included := make([]string, 0, len(stateRoots))

	for capability, root := range stateRoots {
		if dirHasContent(root) {
			included = append(included, capability)
		}
	}

	sort.Strings(included)

	return included
}

func dirHasContent(root string) bool {
	entries, err := os.ReadDir(root)
	if err != nil {
		return false
	}

	return len(entries) > 0
}

// archiveStateRoots walks every included capability's own state root, plus
// every dumped database capability's own real SQL dump, and returns one
// combined gzip-compressed tar stream in memory. Each entry's name is
// prefixed by its capability string (e.g. "web.nginx.v1/sites/example.com.conf",
// "database.tenant.v1/dump.sql") so a future restore can tell which
// capability a given path belongs to.
//
// Buffering the whole archive in memory (rather than streaming straight to
// the encryption step) is a deliberate v1 bound: this capability backs up a
// single hosting node's own rendered config-plane state (nginx/bind9/mail/
// cron directories) plus now-real mysqldump/mariadb-dump output for its
// database capabilities, not arbitrary media, so the real-world size here
// stays bounded. A future pass can revisit this if a real deployment's own
// state roots or database dumps ever grow large enough for it to matter.
func archiveStateRoots(ctx context.Context, sudoBinary, agentBinaryPath string, stateRoots map[string]string, included []string, dumps map[string][]byte, dumpedCapabilities []string) ([]byte, error) {
	var buf bytes.Buffer

	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)

	for _, capability := range included {
		// scheduler.account-cron.v1's own StateRoots entry contains
		// StateRoot/accounts/<run_as>, deliberately root:<run_as> mode
		// 2750 (see cron package's own ensureAccountDir doc comment) --
		// never readable by the shared lesta group this process itself
		// runs as. Root-mediated via the same sudo mechanism
		// dumpSocket/restoreSocket already use, rather than a direct
		// walk, which would fail with a permission error, confirmed
		// directly deploying to a real node.
		if capability == schedulerCronCapability && sudoBinary != "" && agentBinaryPath != "" {
			nested, err := archiveCronStatePrivileged(ctx, sudoBinary, agentBinaryPath)
			if err != nil {
				return nil, fmt.Errorf("archiving %s: %w", capability, err)
			}

			if err := mergeNestedArchive(tw, nested, capability); err != nil {
				return nil, fmt.Errorf("archiving %s: %w", capability, err)
			}

			continue
		}

		if err := addDirToTar(tw, stateRoots[capability], capability); err != nil {
			return nil, fmt.Errorf("archiving %s: %w", capability, err)
		}
	}

	for _, capability := range dumpedCapabilities {
		if err := addBytesToTar(tw, dumps[capability], capability+"/dump.sql"); err != nil {
			return nil, fmt.Errorf("archiving %s's own dump: %w", capability, err)
		}
	}

	if err := tw.Close(); err != nil {
		return nil, fmt.Errorf("closing tar writer: %w", err)
	}

	if err := gz.Close(); err != nil {
		return nil, fmt.Errorf("closing gzip writer: %w", err)
	}

	return buf.Bytes(), nil
}

// addBytesToTar writes content as a single regular-file tar entry named
// name, for data that was never a real file on disk (a mysqldump/
// mariadb-dump's own stdout), mirroring addDirToTar's own header
// construction for a real file.
func addBytesToTar(tw *tar.Writer, content []byte, name string) error {
	hdr := &tar.Header{
		Name:    name,
		Mode:    0o640,
		Size:    int64(len(content)),
		ModTime: time.Now(),
	}

	if err := tw.WriteHeader(hdr); err != nil {
		return err
	}

	_, err := tw.Write(content)

	return err
}

// archiveCronStatePrivileged execs this same agent binary's own
// "cron-archive-state" CLI mode via sudo, root-mediated exactly like
// dumpSocket's/restoreSocket's own mariadb-dump/mariadb invocations, and
// returns its real gzip-compressed tar of scheduler.account-cron.v1's own
// entire StateRoot (see cron.ArchiveState's own doc comment for why this
// exception exists).
func archiveCronStatePrivileged(ctx context.Context, sudoBinary, agentBinaryPath string) ([]byte, error) {
	cmd := exec.CommandContext(ctx, sudoBinary, agentBinaryPath, "cron-archive-state")

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return nil, fmt.Errorf("%s: %w: %s", cmd.Path, err, strings.TrimSpace(stderr.String()))
	}

	return stdout.Bytes(), nil
}

// mergeNestedArchive decompresses a gzip-compressed tar stream produced by
// a privileged helper invocation (archiveCronStatePrivileged) and copies
// every entry into tw, renaming each one from its own root-relative name
// (e.g. "accounts/lesta-cron/jobs/sidecar/<id>.json") to
// "<prefix>/<that name>" -- the same "<capability>/..." naming convention
// addDirToTar already establishes for every other capability's own entries.
func mergeNestedArchive(tw *tar.Writer, nested []byte, prefix string) error {
	gz, err := gzip.NewReader(bytes.NewReader(nested))
	if err != nil {
		return fmt.Errorf("opening nested gzip stream: %w", err)
	}
	defer gz.Close()

	tr := tar.NewReader(gz)

	for {
		hdr, err := tr.Next()
		if err == io.EOF {
			return nil
		}
		if err != nil {
			return fmt.Errorf("reading nested tar entry: %w", err)
		}

		hdr.Name = prefix + "/" + hdr.Name

		if err := tw.WriteHeader(hdr); err != nil {
			return fmt.Errorf("writing merged entry %s: %w", hdr.Name, err)
		}

		if hdr.Typeflag == tar.TypeReg {
			if _, err := io.Copy(tw, tr); err != nil {
				return fmt.Errorf("copying merged entry %s: %w", hdr.Name, err)
			}
		}
	}
}

func addDirToTar(tw *tar.Writer, root, prefix string) error {
	return filepath.Walk(root, func(path string, info os.FileInfo, err error) error {
		if err != nil {
			return err
		}

		rel, err := filepath.Rel(root, path)
		if err != nil {
			return err
		}

		name := prefix
		if rel != "." {
			name = prefix + "/" + filepath.ToSlash(rel)
		}

		if info.IsDir() {
			if rel == "." {
				return nil
			}

			hdr, err := tar.FileInfoHeader(info, "")
			if err != nil {
				return err
			}

			hdr.Name = name + "/"

			return tw.WriteHeader(hdr)
		}

		if !info.Mode().IsRegular() {
			return nil
		}

		hdr, err := tar.FileInfoHeader(info, "")
		if err != nil {
			return err
		}

		hdr.Name = name

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
}
