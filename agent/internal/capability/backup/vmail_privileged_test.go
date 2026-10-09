package backup

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
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

func TestMailFilesSurviveThePackAndReadRoundTripWithFolders(t *testing.T) {
	files := []vmailFile{
		{relPath: "example.com", mode: 0o700, uid: os.Getuid(), gid: os.Getgid(), isDir: true},
		{relPath: "example.com/bob/cur/2", content: []byte("hi"), mode: 0o600, uid: os.Getuid(), gid: os.Getgid()},
	}

	var request bytes.Buffer

	gz := gzip.NewWriter(&request)
	tw := tar.NewWriter(gz)

	for _, f := range files {
		if f.isDir {
			_ = tw.WriteHeader(&tar.Header{Name: f.relPath + "/", Mode: int64(f.mode), Uid: f.uid, Gid: f.gid, Typeflag: tar.TypeDir})

			continue
		}

		_ = tw.WriteHeader(&tar.Header{Name: f.relPath, Mode: int64(f.mode), Size: int64(len(f.content)), Uid: f.uid, Gid: f.gid, Typeflag: tar.TypeReg})
		_, _ = tw.Write(f.content)
	}

	_ = tw.Close()
	_ = gz.Close()

	got, err := readMailFiles(&request)
	if err != nil {
		t.Fatal(err)
	}

	if len(got) != 2 || !got[0].isDir || got[0].relPath != "example.com" || string(got[1].content) != "hi" {
		t.Errorf("unexpected round trip: %+v", got)
	}
}
