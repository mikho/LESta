package cron_test

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"io"
	"os"
	"path/filepath"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/cron"
)

// TestArchiveStateProducesAGzippedTarOfTheWholeStateRoot proves ArchiveState's
// own real gzip+tar output round-trips a real nested directory (mirroring
// StateRoot/accounts/<run_as>/jobs/sidecar/<id>.json's own real shape) back
// out correctly, with entry names relative to StateRoot itself -- exactly
// what backup.encrypted-artifacts.v1's own archive step expects to
// re-prefix with "scheduler.account-cron.v1/".
func TestArchiveStateProducesAGzippedTarOfTheWholeStateRoot(t *testing.T) {
	stateRoot := t.TempDir()

	sidecarDir := filepath.Join(stateRoot, "accounts", "lesta-cron", "jobs", "sidecar")
	if err := os.MkdirAll(sidecarDir, 0o750); err != nil {
		t.Fatalf("seeding a real nested directory: %v", err)
	}

	sidecarContent := []byte(`{"schedule":"* * * * *"}`)
	sidecarPath := filepath.Join(sidecarDir, "resource-1.json")
	if err := os.WriteFile(sidecarPath, sidecarContent, 0o640); err != nil {
		t.Fatalf("seeding a real sidecar file: %v", err)
	}

	var buf bytes.Buffer
	if code := cron.ArchiveState(cron.Config{StateRoot: stateRoot}, &buf); code != 0 {
		t.Fatalf("expected exit code 0, got %d", code)
	}

	entries := readGzippedTarEntries(t, buf.Bytes())

	got, ok := entries["accounts/lesta-cron/jobs/sidecar/resource-1.json"]
	if !ok {
		t.Fatalf("expected a sidecar entry, got entries: %v", mapKeys(entries))
	}

	if got != string(sidecarContent) {
		t.Fatalf("expected the sidecar content to round-trip exactly, got %q", got)
	}
}

func readGzippedTarEntries(t *testing.T, raw []byte) map[string]string {
	t.Helper()

	gz, err := gzip.NewReader(bytes.NewReader(raw))
	if err != nil {
		t.Fatalf("opening gzip stream: %v", err)
	}
	defer gz.Close()

	tr := tar.NewReader(gz)
	entries := make(map[string]string)

	for {
		hdr, err := tr.Next()
		if err == io.EOF {
			break
		}
		if err != nil {
			t.Fatalf("reading tar entry: %v", err)
		}

		if hdr.Typeflag != tar.TypeReg {
			continue
		}

		content, err := io.ReadAll(tr)
		if err != nil {
			t.Fatalf("reading content of %s: %v", hdr.Name, err)
		}

		entries[hdr.Name] = string(content)
	}

	return entries
}

func mapKeys(m map[string]string) []string {
	keys := make([]string, 0, len(m))
	for k := range m {
		keys = append(keys, k)
	}

	return keys
}
