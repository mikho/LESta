package nginx_test

import (
	"os"
	"os/user"
	"path/filepath"
	"strconv"
	"syscall"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/nginx"
)

// TestEnsureDocrootCreatesOwnedWorldReadableDirectory proves EnsureDocroot's
// real create path end to end: a fresh AccountsRoot/<username>/domains/
// <resourceID>/public directory appears, owned by username's own uid/gid,
// world-readable (0755) -- the exact permission shape nginx (serving as
// www-data) and this same account's own SFTP session both need, per
// docroot.go's own doc comment. Requires root (a real os.Chown to an
// arbitrary uid/gid needs it), skipping cleanly otherwise, mirroring
// identity's own requireRootAndUseradd pattern.
func TestEnsureDocrootCreatesOwnedWorldReadableDirectory(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("not running as root; skipping the real chown contract test")
	}

	currentUser, err := user.Current()
	if err != nil {
		t.Fatalf("looking up current user: %v", err)
	}

	accountsRoot := t.TempDir()
	cfg := nginx.Config{AccountsRoot: accountsRoot}

	resourceID := "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"

	if code := nginx.EnsureDocroot(cfg, currentUser.Username, resourceID); code != 0 {
		t.Fatalf("EnsureDocroot exited %d, expected 0", code)
	}

	docroot := filepath.Join(accountsRoot, currentUser.Username, "domains", resourceID, "public")

	info, err := os.Stat(docroot)
	if err != nil {
		t.Fatalf("stat docroot: %v", err)
	}

	if !info.IsDir() {
		t.Fatalf("expected %s to be a directory", docroot)
	}

	if got, want := info.Mode().Perm(), os.FileMode(0o755); got != want {
		t.Fatalf("docroot mode = %o, want %o", got, want)
	}

	wantUID, err := strconv.Atoi(currentUser.Uid)
	if err != nil {
		t.Fatalf("parsing current uid: %v", err)
	}

	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok {
		t.Fatal("expected a *syscall.Stat_t from info.Sys()")
	}

	if got := int(stat.Uid); got != wantUID {
		t.Fatalf("docroot owner uid = %d, want %d", got, wantUID)
	}

	// Calling again must be a harmless no-op, not an error (create then a
	// later update both call this).
	if code := nginx.EnsureDocroot(cfg, currentUser.Username, resourceID); code != 0 {
		t.Fatalf("second EnsureDocroot exited %d, expected 0", code)
	}
}

func TestEnsureDocrootRejectsInvalidUsername(t *testing.T) {
	cfg := nginx.Config{AccountsRoot: t.TempDir()}

	if code := nginx.EnsureDocroot(cfg, "Not Valid!", "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee"); code == 0 {
		t.Fatal("expected a non-zero exit code for an invalid username")
	}
}

func TestEnsureDocrootRejectsInvalidResourceID(t *testing.T) {
	cfg := nginx.Config{AccountsRoot: t.TempDir()}

	if code := nginx.EnsureDocroot(cfg, "lesta-t1", "not-a-uuid"); code == 0 {
		t.Fatal("expected a non-zero exit code for an invalid resource id")
	}
}
