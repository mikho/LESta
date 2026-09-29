package cron_test

import (
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/cron"
)

const testValidResourceID = "9d3f2b1a-4c5e-4a6b-8c7d-1e2f3a4b5c6d"

func TestInstallFragmentWritesContentForAValidResourceID(t *testing.T) {
	dir := t.TempDir()
	cfg := cron.Config{FragmentDir: dir}

	if code := cron.InstallFragment(cfg, testValidResourceID, strings.NewReader("* * * * * root true\n")); code != 0 {
		t.Fatalf("InstallFragment returned %d, want 0", code)
	}

	got, err := os.ReadFile(filepath.Join(dir, "lesta-"+testValidResourceID))
	if err != nil {
		t.Fatalf("reading installed fragment: %v", err)
	}

	if string(got) != "* * * * * root true\n" {
		t.Fatalf("installed fragment content = %q, want the exact content passed in", got)
	}
}

func TestInstallFragmentRejectsAnInvalidResourceID(t *testing.T) {
	dir := t.TempDir()
	cfg := cron.Config{FragmentDir: dir}

	if code := cron.InstallFragment(cfg, "../etc/passwd", strings.NewReader("malicious\n")); code == 0 {
		t.Fatalf("InstallFragment accepted a non-UUID resource id; must reject before ever touching the filesystem")
	}

	entries, err := os.ReadDir(dir)
	if err != nil {
		t.Fatalf("reading fragment dir: %v", err)
	}

	if len(entries) != 0 {
		t.Fatalf("InstallFragment wrote something despite rejecting the resource id: %v", entries)
	}
}

func TestRemoveFragmentRemovesAnExistingFragmentAndToleratesRepeat(t *testing.T) {
	dir := t.TempDir()
	cfg := cron.Config{FragmentDir: dir}

	if code := cron.InstallFragment(cfg, testValidResourceID, strings.NewReader("* * * * * root true\n")); code != 0 {
		t.Fatalf("InstallFragment returned %d, want 0", code)
	}

	if code := cron.RemoveFragment(cfg, testValidResourceID); code != 0 {
		t.Fatalf("RemoveFragment returned %d, want 0", code)
	}

	if _, err := os.Stat(filepath.Join(dir, "lesta-"+testValidResourceID)); !os.IsNotExist(err) {
		t.Fatalf("fragment still exists after RemoveFragment: err=%v", err)
	}

	// Repeat: a resource whose create never landed, or a retried delete,
	// must stay idempotent -- matching applyDelete's own pre-existing
	// contract, now enforced inside RemoveFragment itself.
	if code := cron.RemoveFragment(cfg, testValidResourceID); code != 0 {
		t.Fatalf("second RemoveFragment (already absent) returned %d, want 0", code)
	}
}

func TestRemoveFragmentRejectsAnInvalidResourceID(t *testing.T) {
	cfg := cron.Config{FragmentDir: t.TempDir()}

	if code := cron.RemoveFragment(cfg, "not-a-uuid"); code == 0 {
		t.Fatalf("RemoveFragment accepted a non-UUID resource id; must reject rather than attempt any removal")
	}
}

func TestEnsureAccountDirCreatesTheRealDirectory(t *testing.T) {
	requireChownableGroup(t)

	stateRoot := t.TempDir()
	cfg := cron.Config{StateRoot: stateRoot}

	if code := cron.EnsureAccountDir(cfg, testRunAs); code != 0 {
		t.Fatalf("EnsureAccountDir returned %d, want 0", code)
	}

	info, err := os.Stat(filepath.Join(stateRoot, "accounts", testRunAs))
	if err != nil {
		t.Fatalf("account directory was not created: %v", err)
	}

	if !info.IsDir() {
		t.Fatalf("expected a directory at accounts/%s", testRunAs)
	}
}

func TestEnsureAccountDirRejectsAnInvalidRunAs(t *testing.T) {
	cfg := cron.Config{StateRoot: t.TempDir()}

	if code := cron.EnsureAccountDir(cfg, "../etc"); code == 0 {
		t.Fatalf("EnsureAccountDir accepted a run_as containing a path separator; must reject before ever touching the filesystem")
	}
}
