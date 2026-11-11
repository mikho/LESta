package backup

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func mailTar(t *testing.T, entries []tar.Header, bodies map[string]string) *bytes.Buffer {
	t.Helper()

	var buf bytes.Buffer

	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)

	for _, h := range entries {
		h := h
		body := bodies[h.Name]

		if h.Typeflag == 0 {
			h.Typeflag = tar.TypeReg
		}

		if h.Typeflag == tar.TypeReg {
			h.Size = int64(len(body))
		}

		if h.Mode == 0 {
			h.Mode = 0o640
		}

		h.Uid, h.Gid = os.Getuid(), os.Getgid()

		if err := tw.WriteHeader(&h); err != nil {
			t.Fatal(err)
		}

		if h.Typeflag == tar.TypeReg {
			if _, err := tw.Write([]byte(body)); err != nil {
				t.Fatal(err)
			}
		}
	}

	_ = tw.Close()
	_ = gz.Close()

	return &buf
}

func TestArchiveMailStateNamesEntriesRelativeToTheRootAndKeepsFolders(t *testing.T) {
	root := t.TempDir()

	if err := os.MkdirAll(filepath.Join(root, "vmail", "example.com", "alice", "cur"), 0o750); err != nil {
		t.Fatal(err)
	}

	if err := os.WriteFile(filepath.Join(root, "vmail", "example.com", "alice", "cur", "1"), []byte("hello"), 0o640); err != nil {
		t.Fatal(err)
	}

	var out bytes.Buffer

	if code := ArchiveMailState(Config{StateRoots: map[string]string{mailCapability: root}}, &out); code != 0 {
		t.Fatalf("exit code %d", code)
	}

	gz, err := gzip.NewReader(&out)
	if err != nil {
		t.Fatal(err)
	}

	tr := tar.NewReader(gz)
	names := map[string]byte{}

	for {
		h, err := tr.Next()
		if err != nil {
			break
		}

		names[h.Name] = h.Typeflag
	}

	if names["vmail/example.com/alice/cur/1"] != tar.TypeReg || names["vmail/example.com/alice/cur/"] != tar.TypeDir {
		t.Errorf("unexpected entries: %v", names)
	}

	if code := ArchiveMailState(Config{}, &out); code == 0 {
		t.Errorf("a node with no mail state root must fail")
	}
}

func TestRestoreMailFilesRestoresFoldersAndFilesAndRefusesUnsafeNames(t *testing.T) {
	vmail := filepath.Join(t.TempDir(), "vmail")
	cfg := Config{VMailRoot: vmail}

	good := mailTar(t, []tar.Header{
		{Name: "example.com/", Typeflag: tar.TypeDir, Mode: 0o700},
		{Name: "example.com/alice/", Typeflag: tar.TypeDir, Mode: 0o700},
		{Name: "example.com/alice/cur/1"},
		{Name: mailCommitEntry},
	}, map[string]string{"example.com/alice/cur/1": "a message"})

	if code := RestoreMailFiles(cfg, good); code != 0 {
		t.Fatalf("exit code %d", code)
	}

	if b, _ := os.ReadFile(filepath.Join(vmail, "example.com", "alice", "cur", "1")); string(b) != "a message" {
		t.Errorf("the message did not come back: %q", b)
	}

	if info, err := os.Stat(filepath.Join(vmail, "example.com", "alice")); err != nil || info.Mode().Perm() != 0o700 {
		t.Errorf("a folder must keep its recorded mode: %v %v", info, err)
	}

	for name, entries := range map[string][]tar.Header{
		"climbing":      {{Name: "example.com/../../etc/x"}},
		"absolute":      {{Name: "/etc/cron.d/x"}},
		"bad domain":    {{Name: "not a domain/x/y"}},
		"domain only":   {{Name: "example.com"}},
		"dot segment":   {{Name: "example.com/./x"}},
		"folder escape": {{Name: "example.com/../x/", Typeflag: tar.TypeDir}},
	} {
		other := filepath.Join(t.TempDir(), "vmail")

		if code := RestoreMailFiles(Config{VMailRoot: other}, mailTar(t, entries, map[string]string{})); code == 0 {
			t.Errorf("%s: an unsafe request must be refused", name)
		}

		if _, err := os.Stat(other); err == nil {
			t.Errorf("%s: nothing may be created for a refused request", name)
		}
	}

	if code := RestoreMailFiles(Config{}, good); code == 0 {
		t.Errorf("a node with no mail root must fail")
	}

	if code := RestoreMailFiles(cfg, strings.NewReader("not a gzip stream")); code == 0 {
		t.Errorf("garbage must be refused")
	}
}

func TestARestoreRequestWithoutItsCommitEntryRestoresNothing(t *testing.T) {
	vmail := filepath.Join(t.TempDir(), "vmail")

	cutShort := mailTar(t, []tar.Header{
		{Name: "example.com/", Typeflag: tar.TypeDir, Mode: 0o700},
		{Name: "example.com/alice/cur/1"},
	}, map[string]string{"example.com/alice/cur/1": "a message"})

	if code := RestoreMailFiles(Config{VMailRoot: vmail}, cutShort); code == 0 {
		t.Fatal("a request that never committed must fail")
	}

	if _, err := os.Stat(filepath.Join(vmail, "example.com")); err == nil {
		t.Error("nothing may be made live without the commit entry")
	}

	leftovers, _ := filepath.Glob(filepath.Join(filepath.Dir(vmail), "vmail-restore-*"))
	if len(leftovers) != 0 {
		t.Errorf("the staging directory was left behind: %v", leftovers)
	}
}

func TestAWholeNodeRestoreRefusesAMailPathOutsideItsDomain(t *testing.T) {
	for _, name := range []string{"../escape/x", "example.com/../../etc/cron.d/x", "/etc/passwd"} {
		archive := mailTar(t, []tar.Header{{Name: vmailPrefix + name, Mode: 0o600, Size: 1, Typeflag: tar.TypeReg}}, map[string]string{vmailPrefix + name: "x"})

		c := New(Config{ArtifactsRoot: t.TempDir(), VMailRoot: filepath.Join(t.TempDir(), "vmail")})

		if _, failure := c.restoreFromStream(context.Background(), bytes.NewReader(archive.Bytes())); failure == nil {
			t.Errorf("%s: an unsafe mail path must be refused", name)
		}
	}
}

func TestRestoredMailIsNeverRootsNorWorldAccessible(t *testing.T) {
	root := filepath.Join(t.TempDir(), "vmail")

	if err := restoreVMail([]vmailFile{{relPath: "example.com/bob/cur/1", content: []byte("x"), mode: 0o600}}, root); err == nil {
		t.Error("a root-owned entry must be refused")
	}

	err := restoreVMail([]vmailFile{
		{relPath: "example.com", mode: 0o777, uid: os.Getuid(), gid: os.Getgid(), isDir: true},
		{relPath: "example.com/bob/cur/1", content: []byte("x"), mode: 0o666, uid: os.Getuid(), gid: os.Getgid()},
	}, root)
	if err != nil {
		t.Fatalf("restoreVMail: %v", err)
	}

	for path, want := range map[string]os.FileMode{"example.com": 0o770, "example.com/bob/cur/1": 0o660} {
		info, err := os.Stat(filepath.Join(root, path))
		if err != nil {
			t.Fatal(err)
		}

		if info.Mode().Perm()&0o007 != 0 || info.Mode().Perm()&^want != 0 {
			t.Errorf("%s: mode %v, want no more than %v", path, info.Mode().Perm(), want)
		}
	}
}
