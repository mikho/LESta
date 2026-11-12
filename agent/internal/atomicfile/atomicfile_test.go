package atomicfile

import (
	"os"
	"path/filepath"
	"syscall"
	"testing"
)

func TestWriteCreatesTheFileWithExactlyTheModeAndNoTemporaryLeft(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "sub", "file.conf")

	old := syscall.Umask(0o077)
	defer syscall.Umask(old)

	if err := Write(path, []byte("hello"), 0o644); err != nil {
		t.Fatal(err)
	}

	got, _ := os.ReadFile(path)
	if string(got) != "hello" {
		t.Fatalf("content %q", got)
	}

	info, _ := os.Stat(path)
	if info.Mode().Perm() != 0o644 {
		t.Fatalf("mode %o, want 644 despite the umask", info.Mode().Perm())
	}

	if _, err := os.Stat(path + ".tmp"); !os.IsNotExist(err) {
		t.Fatalf("the temporary file was left behind: %v", err)
	}
}

func TestWriteReplacesAnExistingFileWholesale(t *testing.T) {
	path := filepath.Join(t.TempDir(), "file")
	if err := os.WriteFile(path, []byte("a much longer earlier content"), 0o600); err != nil {
		t.Fatal(err)
	}

	if err := Write(path, []byte("short"), 0o600); err != nil {
		t.Fatal(err)
	}

	if got, _ := os.ReadFile(path); string(got) != "short" {
		t.Fatalf("content %q", got)
	}
}

func TestAFailedWriteLeavesNeitherTheTargetNorTheTemporaryFile(t *testing.T) {
	dir := t.TempDir()
	target := filepath.Join(dir, "target")

	if err := os.Mkdir(target, 0o755); err != nil { // renaming a file over a directory fails
		t.Fatal(err)
	}

	if err := Write(target, []byte("x"), 0o644); err == nil {
		t.Fatal("expected the rename onto a directory to fail")
	}

	if _, err := os.Stat(target + ".tmp"); !os.IsNotExist(err) {
		t.Fatalf("the temporary file was left behind: %v", err)
	}
}

func TestWriteOwnedRejectsAnUnknownGroupBeforeWritingAnything(t *testing.T) {
	path := filepath.Join(t.TempDir(), "file")

	if err := WriteOwned(path, []byte("x"), 0o640, "no-such-group-lesta"); err == nil {
		t.Fatal("expected an unknown group to be refused")
	}

	if _, err := os.Stat(path); !os.IsNotExist(err) {
		t.Fatalf("a file was written despite the refused group: %v", err)
	}
}
