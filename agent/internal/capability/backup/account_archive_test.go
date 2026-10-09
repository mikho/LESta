package backup

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"errors"
	"io"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func writeTree(t *testing.T, root string, files map[string]string) {
	t.Helper()

	for name, content := range files {
		p := filepath.Join(root, filepath.FromSlash(name))
		if err := os.MkdirAll(filepath.Dir(p), 0o755); err != nil {
			t.Fatalf("mkdir: %v", err)
		}

		if err := os.WriteFile(p, []byte(content), 0o644); err != nil {
			t.Fatalf("write: %v", err)
		}
	}
}

func readFile(t *testing.T, p string) string {
	t.Helper()

	b, err := os.ReadFile(p)
	if err != nil {
		t.Fatalf("reading %s: %v", p, err)
	}

	return string(b)
}

func TestAccountArchiveRoundTripsFilesMailAndDatabases(t *testing.T) {
	key := testKey(t)
	docroot, mail, dumpDir := t.TempDir(), t.TempDir(), t.TempDir()

	writeTree(t, docroot, map[string]string{"index.html": "<h1>hello</h1>", "assets/app.js": "console.log(1)", "empty/.keep": ""})
	writeTree(t, mail, map[string]string{"alice/cur/1:2,S": "mail one", "alice/dovecot.index.cache": "cache", "alice/dovecot-uidlist": "uids"})

	if err := os.Symlink("index.html", filepath.Join(docroot, "home")); err != nil {
		t.Fatal(err)
	}

	if err := os.Symlink("/etc/passwd", filepath.Join(docroot, "outside")); err != nil {
		t.Fatal(err)
	}

	dump := filepath.Join(dumpDir, "shop.sql")
	if err := os.WriteFile(dump, []byte("CREATE TABLE t (id int);"), 0o600); err != nil {
		t.Fatal(err)
	}

	var sealed bytes.Buffer

	report, err := writeAccountArchive(&sealed, key, archiveSource{
		Username:  "lesta-t1",
		Databases: []dbDump{{Name: "shop", Path: dump}},
		MailDirs:  map[string]string{"example.com": mail},
		DocRoots:  map[string]string{"11111111-1111-1111-1111-111111111111": docroot},
	})
	if err != nil {
		t.Fatalf("writeAccountArchive: %v", err)
	}

	if report.Parts[partFiles].Entries == 0 || report.Parts[partMail].Entries == 0 || report.Parts[partDatabases].Entries != 1 {
		t.Errorf("unexpected report: %+v", report)
	}

	skippedOutside := false
	for _, s := range report.Skipped {
		if strings.Contains(s, "outside") {
			skippedOutside = true
		}
	}

	if !skippedOutside {
		t.Errorf("a link leaving the folder must be skipped and reported: %v", report.Skipped)
	}

	// Restore into fresh folders.
	newDocroot, newMail := t.TempDir(), t.TempDir()
	var restoredSQL strings.Builder

	got, err := restoreAccountArchive(bytes.NewReader(sealed.Bytes()), key, restoreTarget{
		Parts:     map[string]bool{partFiles: true, partMail: true, partDatabases: true},
		DocRoots:  map[string]string{"11111111-1111-1111-1111-111111111111": newDocroot},
		MailDirs:  map[string]string{"example.com": newMail},
		Databases: map[string]bool{"shop": true},
		RestoreDatabase: func(name string, sql io.Reader) error {
			b, _ := io.ReadAll(sql)
			restoredSQL.WriteString(name + ":" + string(b))

			return nil
		},
		Chown: func(string, int, int) error { return nil },
	})
	if err != nil {
		t.Fatalf("restoreAccountArchive: %v", err)
	}

	if len(got.Restored) != 3 {
		t.Errorf("expected all three parts restored, got %+v", got)
	}

	if readFile(t, filepath.Join(newDocroot, "index.html")) != "<h1>hello</h1>" || readFile(t, filepath.Join(newDocroot, "assets/app.js")) != "console.log(1)" {
		t.Errorf("the site files did not come back")
	}

	if link, err := os.Readlink(filepath.Join(newDocroot, "home")); err != nil || link != "index.html" {
		t.Errorf("an inside link must be restored: %q %v", link, err)
	}

	if _, err := os.Lstat(filepath.Join(newDocroot, "outside")); err == nil {
		t.Errorf("a link that left the folder must not come back")
	}

	if _, err := os.Stat(filepath.Join(newDocroot, "empty")); err != nil {
		t.Errorf("an empty folder must come back: %v", err)
	}

	if readFile(t, filepath.Join(newMail, "alice/cur/1:2,S")) != "mail one" || readFile(t, filepath.Join(newMail, "alice/dovecot-uidlist")) != "uids" {
		t.Errorf("the mailbox did not come back")
	}

	if _, err := os.Stat(filepath.Join(newMail, "alice/dovecot.index.cache")); err == nil {
		t.Errorf("rebuildable Dovecot caches must not be part of a backup")
	}

	if restoredSQL.String() != "shop:CREATE TABLE t (id int);" {
		t.Errorf("unexpected database restore: %q", restoredSQL.String())
	}
}

func TestRestoreOnlyAppliesTheSelectedPartsAndKnownTargets(t *testing.T) {
	key := testKey(t)
	docroot, mail := t.TempDir(), t.TempDir()
	writeTree(t, docroot, map[string]string{"a.txt": "a"})
	writeTree(t, mail, map[string]string{"bob/cur/1": "m"})

	var sealed bytes.Buffer
	if _, err := writeAccountArchive(&sealed, key, archiveSource{
		Username: "lesta-t1",
		MailDirs: map[string]string{"example.com": mail},
		DocRoots: map[string]string{"11111111-1111-1111-1111-111111111111": docroot, "22222222-2222-2222-2222-222222222222": docroot},
	}); err != nil {
		t.Fatal(err)
	}

	dest, other := t.TempDir(), t.TempDir()

	got, err := restoreAccountArchive(bytes.NewReader(sealed.Bytes()), key, restoreTarget{
		Parts:    map[string]bool{partFiles: true},
		DocRoots: map[string]string{"11111111-1111-1111-1111-111111111111": dest},
		MailDirs: map[string]string{"example.com": other},
	})
	if err != nil {
		t.Fatal(err)
	}

	if len(got.Restored) != 1 || got.Restored[0] != partFiles {
		t.Errorf("only the files part was selected: %+v", got)
	}

	if _, err := os.Stat(filepath.Join(dest, "a.txt")); err != nil {
		t.Errorf("the selected site must be restored: %v", err)
	}

	if entries, _ := os.ReadDir(other); len(entries) != 0 {
		t.Errorf("mail was not selected and must not be written")
	}
}

// hostileArchive seals a tar with the given entries, as an attacker who holds
// the key (or a bug in the writer) could produce it.
func hostileArchive(t *testing.T, key string, entries []tar.Header, bodies map[string]string) []byte {
	t.Helper()

	var sealed bytes.Buffer

	sw, err := newSealWriter(&sealed, key)
	if err != nil {
		t.Fatal(err)
	}

	gz := gzip.NewWriter(sw)
	tw := tar.NewWriter(gz)

	for _, h := range entries {
		h := h
		body := bodies[h.Name]
		h.Size = int64(len(body))

		if h.Typeflag == 0 {
			h.Typeflag = tar.TypeReg
		}

		if h.Mode == 0 {
			h.Mode = 0o644
		}

		if err := tw.WriteHeader(&h); err != nil {
			t.Fatal(err)
		}

		if _, err := tw.Write([]byte(body)); err != nil {
			t.Fatal(err)
		}
	}

	_ = tw.Close()
	_ = gz.Close()
	_ = sw.Close()

	return sealed.Bytes()
}

func TestRestoreRefusesEntriesThatWouldWriteOutsideTheAccount(t *testing.T) {
	key := testKey(t)
	id := "11111111-1111-1111-1111-111111111111"
	parent := t.TempDir()
	dest := filepath.Join(parent, "public")

	if err := os.MkdirAll(dest, 0o755); err != nil {
		t.Fatal(err)
	}

	secret := filepath.Join(parent, "secret.txt")
	if err := os.WriteFile(secret, []byte("untouched"), 0o600); err != nil {
		t.Fatal(err)
	}

	sealed := hostileArchive(t, key, []tar.Header{
		{Name: "files/" + id + "/../secret.txt"},
		{Name: "files/" + id + "/a/../../secret.txt"},
		{Name: "/etc/cron.d/evil"},
		{Name: "files/" + id + "//absolute-ish"},
		{Name: "files/" + id + "/dev", Typeflag: tar.TypeChar},
		{Name: "files/" + id + "/fifo", Typeflag: tar.TypeFifo},
		{Name: "files/" + id + "/hard", Typeflag: tar.TypeLink, Linkname: "../secret.txt"},
		{Name: "files/" + id + "/escape", Typeflag: tar.TypeSymlink, Linkname: "../secret.txt"},
		{Name: "files/" + id + "/abs", Typeflag: tar.TypeSymlink, Linkname: "/etc/shadow"},
		{Name: "files/" + id + "/sub/deep", Typeflag: tar.TypeSymlink, Linkname: "../../../secret.txt"},
		{Name: "files/" + id + "/ok.txt"},
		{Name: "files/99999999-9999-9999-9999-999999999999/other-site.txt"},
		{Name: "databases/../../x.sql"},
		{Name: "unknown/thing/file"},
	}, map[string]string{
		"files/" + id + "/ok.txt": "fine",
	})

	got, err := restoreAccountArchive(bytes.NewReader(sealed), key, restoreTarget{
		Parts:    map[string]bool{partFiles: true, partMail: true, partDatabases: true},
		DocRoots: map[string]string{id: dest},
	})
	if err != nil {
		t.Fatalf("a hostile archive must be handled, not crash: %v", err)
	}

	if readFile(t, secret) != "untouched" {
		t.Fatalf("a restore wrote outside the site folder")
	}

	for _, name := range []string{"dev", "fifo", "hard", "escape", "abs", "sub/deep"} {
		if _, err := os.Lstat(filepath.Join(dest, name)); err == nil {
			t.Errorf("%s must not be restored", name)
		}
	}

	if _, err := os.Stat("/etc/cron.d/evil"); err == nil {
		t.Fatalf("an absolute entry was written")
	}

	if readFile(t, filepath.Join(dest, "ok.txt")) != "fine" {
		t.Errorf("a normal file next to hostile ones must still restore")
	}

	if entries, _ := os.ReadDir(parent); len(entries) != 2 {
		t.Errorf("nothing but public and secret.txt may exist next to the site folder, got %d entries", len(entries))
	}

	if len(got.Skipped) == 0 {
		t.Errorf("refused entries must be reported")
	}
}

func TestRestoreReplacesAPlantedLinkInsteadOfWritingThroughIt(t *testing.T) {
	key := testKey(t)
	id := "11111111-1111-1111-1111-111111111111"
	src, dest, victimDir := t.TempDir(), t.TempDir(), t.TempDir()

	writeTree(t, src, map[string]string{"config.php": "restored", "dir/file.txt": "restored too"})

	var sealed bytes.Buffer
	if _, err := writeAccountArchive(&sealed, key, archiveSource{Username: "u", DocRoots: map[string]string{id: src}}); err != nil {
		t.Fatal(err)
	}

	victim := filepath.Join(victimDir, "victim.txt")
	if err := os.WriteFile(victim, []byte("do not touch"), 0o600); err != nil {
		t.Fatal(err)
	}

	// The tenant plants a link where a file will be restored, and a link where a folder will be.
	if err := os.Symlink(victim, filepath.Join(dest, "config.php")); err != nil {
		t.Fatal(err)
	}

	if err := os.Symlink(victimDir, filepath.Join(dest, "dir")); err != nil {
		t.Fatal(err)
	}

	got, err := restoreAccountArchive(bytes.NewReader(sealed.Bytes()), key, restoreTarget{
		Parts:    map[string]bool{partFiles: true},
		DocRoots: map[string]string{id: dest},
	})
	if err != nil {
		t.Fatal(err)
	}

	if readFile(t, victim) != "do not touch" {
		t.Fatalf("a restore wrote through a planted link")
	}

	if info, err := os.Lstat(filepath.Join(dest, "config.php")); err != nil || info.Mode()&os.ModeSymlink != 0 || readFile(t, filepath.Join(dest, "config.php")) != "restored" {
		t.Errorf("the planted link must be replaced by the restored file")
	}

	if _, err := os.Stat(filepath.Join(victimDir, "file.txt")); err == nil {
		t.Errorf("a file was created inside the folder a planted link pointed at")
	}

	if len(got.Skipped) == 0 {
		t.Errorf("the entry blocked by a planted folder link must be reported")
	}
}

func TestArchiveIsRefusedWhenTheAccountIsTooLarge(t *testing.T) {
	key := testKey(t)
	docroot := t.TempDir()
	writeTree(t, docroot, map[string]string{"big.bin": strings.Repeat("x", 4096)})

	var sealed bytes.Buffer

	_, err := writeAccountArchive(&sealed, key, archiveSource{
		Username: "u",
		DocRoots: map[string]string{"11111111-1111-1111-1111-111111111111": docroot},
		MaxBytes: 1000,
	})
	if !errors.Is(err, ErrBackupTooLarge) {
		t.Errorf("expected ErrBackupTooLarge, got %v", err)
	}
}

func TestRestoreFailsOnATruncatedOrModifiedArchive(t *testing.T) {
	key := testKey(t)
	docroot := t.TempDir()
	writeTree(t, docroot, map[string]string{"a.txt": strings.Repeat("a", 200000)})

	var sealed bytes.Buffer
	if _, err := writeAccountArchive(&sealed, key, archiveSource{Username: "u", DocRoots: map[string]string{"11111111-1111-1111-1111-111111111111": docroot}}); err != nil {
		t.Fatal(err)
	}

	target := func() restoreTarget {
		return restoreTarget{Parts: map[string]bool{partFiles: true}, DocRoots: map[string]string{"11111111-1111-1111-1111-111111111111": t.TempDir()}}
	}

	if _, err := restoreAccountArchive(bytes.NewReader(sealed.Bytes()[:sealed.Len()-40]), key, target()); err == nil {
		t.Errorf("a truncated backup must fail")
	}

	modified := bytes.Clone(sealed.Bytes())
	modified[len(modified)-5] ^= 0xff

	if _, err := restoreAccountArchive(bytes.NewReader(modified), key, target()); err == nil {
		t.Errorf("a modified backup must fail")
	}

	if _, err := restoreAccountArchive(bytes.NewReader(sealed.Bytes()), testKey(t), target()); err == nil {
		t.Errorf("the wrong key must fail")
	}
}
