package nginx

import (
	"os"
	"path/filepath"
	"testing"
)

const testResourceID = "0b5e0c55-3f4a-4c7e-9a3e-6d1f2b8c9a01"

func TestRemoveDomainDirectory_RemovesOnlyThatDomain(t *testing.T) {
	root := t.TempDir()
	mine := filepath.Join(root, "lesta-t1", "domains", testResourceID, "public")
	other := filepath.Join(root, "lesta-t1", "domains", "1b5e0c55-3f4a-4c7e-9a3e-6d1f2b8c9a02", "public")

	for _, dir := range []string{mine, other} {
		if err := os.MkdirAll(dir, 0o755); err != nil {
			t.Fatal(err)
		}
		if err := os.WriteFile(filepath.Join(dir, "index.html"), []byte("hi"), 0o644); err != nil {
			t.Fatal(err)
		}
	}

	if err := RemoveDomainDirectory(root, "lesta-t1", testResourceID); err != nil {
		t.Fatalf("RemoveDomainDirectory: %v", err)
	}

	if _, err := os.Stat(filepath.Dir(mine)); !os.IsNotExist(err) {
		t.Fatalf("the domain's directory is still there: %v", err)
	}
	if _, err := os.Stat(filepath.Join(other, "index.html")); err != nil {
		t.Fatalf("another domain's files were touched: %v", err)
	}

	// Gone already is fine.
	if err := RemoveDomainDirectory(root, "lesta-t1", testResourceID); err != nil {
		t.Fatalf("second call: %v", err)
	}
}

func TestRemoveDomainDirectory_RefusesBadInputAndSymlinks(t *testing.T) {
	root := t.TempDir()
	outside := t.TempDir()
	keep := filepath.Join(outside, "keep.txt")
	if err := os.WriteFile(keep, []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}

	for _, input := range [][2]string{{"../etc", testResourceID}, {"lesta-t1", "../../x"}, {"lesta-t1", "*"}, {"", testResourceID}} {
		if err := RemoveDomainDirectory(root, input[0], input[1]); err == nil {
			t.Fatalf("RemoveDomainDirectory(%q, %q) should refuse", input[0], input[1])
		}
	}

	domains := filepath.Join(root, "lesta-t1", "domains")
	if err := os.MkdirAll(domains, 0o755); err != nil {
		t.Fatal(err)
	}
	if err := os.Symlink(outside, filepath.Join(domains, testResourceID)); err != nil {
		t.Fatal(err)
	}

	if err := RemoveDomainDirectory(root, "lesta-t1", testResourceID); err == nil {
		t.Fatal("a symlinked domain directory should be refused")
	}
	if _, err := os.Stat(keep); err != nil {
		t.Fatalf("the symlink's target was touched: %v", err)
	}
}

func TestRemoveResourceLogs_RemovesLiveAndRotated(t *testing.T) {
	dir := t.TempDir()
	for _, name := range []string{testResourceID + ".access.log", testResourceID + ".access.log.1.gz", testResourceID + ".error.log", "1b5e0c55-3f4a-4c7e-9a3e-6d1f2b8c9a02.access.log"} {
		if err := os.WriteFile(filepath.Join(dir, name), nil, 0o644); err != nil {
			t.Fatal(err)
		}
	}

	if err := RemoveResourceLogs(dir, testResourceID); err != nil {
		t.Fatalf("RemoveResourceLogs: %v", err)
	}

	left, _ := filepath.Glob(filepath.Join(dir, "*"))
	if len(left) != 1 || filepath.Base(left[0]) != "1b5e0c55-3f4a-4c7e-9a3e-6d1f2b8c9a02.access.log" {
		t.Fatalf("left behind %v, want only the other resource's log", left)
	}

	if err := RemoveResourceLogs(dir, "*"); err == nil {
		t.Fatal("a wildcard resource id should be refused")
	}
}

func TestEnsureLogFiles_CreatesWithoutTruncating(t *testing.T) {
	dir := t.TempDir()
	existing := filepath.Join(dir, testResourceID+".access.log")
	if err := os.WriteFile(existing, []byte("old line\n"), 0o644); err != nil {
		t.Fatal(err)
	}
	created := filepath.Join(dir, testResourceID+".error.log")

	if err := ensureLogFiles(Config{}, existing, created); err != nil {
		t.Fatalf("ensureLogFiles: %v", err)
	}

	if content, _ := os.ReadFile(existing); string(content) != "old line\n" {
		t.Fatalf("an existing log was changed: %q", content)
	}
	if info, err := os.Stat(created); err != nil || info.Mode().Perm() != 0o640 {
		t.Fatalf("the new log should be 0640, got %v %v", info, err)
	}
}
