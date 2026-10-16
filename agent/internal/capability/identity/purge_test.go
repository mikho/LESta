package identity

import (
	"os"
	"path/filepath"
	"testing"
)

func TestRemoveChrootTreeRemovesTheWholeTreeAndOnlyThatTree(t *testing.T) {
	root := t.TempDir()
	cfg := Config{AccountsRoot: root}

	for _, dir := range []string{"lesta-t4/public/sub", "lesta-t4/domains/x/public", "lesta-t5/public"} {
		if err := os.MkdirAll(filepath.Join(root, dir), 0o755); err != nil {
			t.Fatal(err)
		}
	}

	outside := filepath.Join(t.TempDir(), "keep")
	if err := os.WriteFile(outside, []byte("x"), 0o644); err != nil {
		t.Fatal(err)
	}

	// A link inside the tree must be removed as a link, never followed.
	if err := os.Symlink(filepath.Dir(outside), filepath.Join(root, "lesta-t4", "public", "escape")); err != nil {
		t.Fatal(err)
	}

	if err := removeChrootTree(cfg, "lesta-t4"); err != nil {
		t.Fatal(err)
	}

	if _, err := os.Stat(filepath.Join(root, "lesta-t4")); !os.IsNotExist(err) {
		t.Errorf("the tree must be gone: %v", err)
	}

	if _, err := os.Stat(filepath.Join(root, "lesta-t5", "public")); err != nil {
		t.Errorf("another account's tree must stay: %v", err)
	}

	if _, err := os.Stat(outside); err != nil {
		t.Errorf("a link's target must stay: %v", err)
	}

	if err := removeChrootTree(cfg, "lesta-t4"); err != nil {
		t.Errorf("an already absent tree is not an error: %v", err)
	}
}

func TestRemoveChrootTreeRefusesAnythingThatIsNotAnAccountDirectly(t *testing.T) {
	root := t.TempDir()

	for _, username := range []string{"", "..", "../x", "a/b", "Upper", "lesta-t4/../.."} {
		if err := removeChrootTree(Config{AccountsRoot: root}, username); err == nil {
			t.Errorf("%q must be refused", username)
		}
	}

	if err := removeChrootTree(Config{}, "lesta-t4"); err == nil {
		t.Errorf("an empty accounts root must be refused")
	}
}

func TestRemoveAuthorizedKeysIgnoresAnAbsentFile(t *testing.T) {
	dir := t.TempDir()
	cfg := Config{AuthorizedKeysDir: dir}

	if err := os.WriteFile(filepath.Join(dir, "lesta-t4"), []byte("key"), 0o644); err != nil {
		t.Fatal(err)
	}

	for i := 0; i < 2; i++ {
		if err := removeAuthorizedKeys(cfg, "lesta-t4"); err != nil {
			t.Fatalf("attempt %d: %v", i, err)
		}
	}

	if _, err := os.Stat(filepath.Join(dir, "lesta-t4")); !os.IsNotExist(err) {
		t.Errorf("the file must be gone: %v", err)
	}
}
