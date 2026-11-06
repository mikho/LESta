package daemon

import (
	"os"
	"path/filepath"
	"testing"
)

func writeProc(t *testing.T, root, name, content string) {
	t.Helper()

	if err := os.WriteFile(filepath.Join(root, name), []byte(content), 0o600); err != nil {
		t.Fatal(err)
	}
}

func TestReadNodeResourcesParsesProc(t *testing.T) {
	root := t.TempDir()
	writeProc(t, root, "loadavg", "0.52 0.40 0.31 2/417 12345\n")
	writeProc(t, root, "uptime", "86400.55 170000.00\n")
	writeProc(t, root, "meminfo", "MemTotal:        2048000 kB\nMemFree:          100000 kB\nMemAvailable:    1024000 kB\nSwapTotal:        512000 kB\nSwapFree:         500000 kB\n")

	got := readNodeResources(root)
	if got == nil {
		t.Fatal("expected resources")
	}

	if got.Load1 != 0.52 || got.Load5 != 0.40 || got.Load15 != 0.31 {
		t.Fatalf("load: %+v", got)
	}

	if got.Processes != 417 || got.UptimeSeconds != 86400 || got.CPUCount < 1 {
		t.Fatalf("processes, uptime or cpu count: %+v", got)
	}

	if got.MemoryTotalKB != 2048000 || got.MemoryAvailableKB != 1024000 || got.SwapTotalKB != 512000 || got.SwapFreeKB != 500000 {
		t.Fatalf("memory: %+v", got)
	}
}

func TestReadNodeResourcesIsNilWithoutLoadAverage(t *testing.T) {
	if got := readNodeResources(t.TempDir()); got != nil {
		t.Fatalf("expected nil, got %+v", got)
	}
}

func TestInstalledPhpVersionsListsOnlyVersionsWithAFpmConfig(t *testing.T) {
	root := t.TempDir()

	for _, version := range []string{"8.4", "8.5"} {
		dir := filepath.Join(root, version, "fpm")
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatal(err)
		}

		if err := os.WriteFile(filepath.Join(dir, "php-fpm.conf"), []byte("[global]\n"), 0o644); err != nil {
			t.Fatal(err)
		}
	}

	// A CLI-only install and a stray directory are not FPM versions.
	if err := os.MkdirAll(filepath.Join(root, "8.3", "cli"), 0o755); err != nil {
		t.Fatal(err)
	}

	if err := os.MkdirAll(filepath.Join(root, "mods-available"), 0o755); err != nil {
		t.Fatal(err)
	}

	got := installedPhpVersions(root)
	if len(got) != 2 || got[0] != "8.4" || got[1] != "8.5" {
		t.Fatalf("got %v", got)
	}

	if installedPhpVersions(filepath.Join(root, "missing")) != nil {
		t.Fatal("a missing directory must give no versions")
	}
}
