package backup

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"fmt"
	"io"
	"os"
	"path/filepath"
	"sort"
)

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

// archiveStateRoots walks every included capability's own state root and
// returns one combined gzip-compressed tar stream in memory. Each entry's
// name is prefixed by its capability string (e.g.
// "web.nginx.v1/sites/example.com.conf") so a future restore can tell which
// capability a given path belongs to.
//
// Buffering the whole archive in memory (rather than streaming straight to
// the encryption step) is a deliberate v1 bound: this capability backs up a
// single hosting node's own rendered config-plane state (nginx/bind9/mail/
// cron/mariadb-tenant-agent-state directories), not database dumps or media,
// so the real-world size here stays small. A future pass can revisit this if
// a real deployment's own state roots ever grow large enough for it to
// matter.
func archiveStateRoots(stateRoots map[string]string, included []string) ([]byte, error) {
	var buf bytes.Buffer

	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)

	for _, capability := range included {
		if err := addDirToTar(tw, stateRoots[capability], capability); err != nil {
			return nil, fmt.Errorf("archiving %s: %w", capability, err)
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
