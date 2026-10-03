package files_test

import (
	"context"
	"crypto/rand"
	"encoding/base64"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"strconv"
	"strings"
	"syscall"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/files"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// requireRootAndUseradd mirrors identity's own helper exactly: a real
// create operation needs a real system user to chown to.
func requireRootAndUseradd(t *testing.T) {
	t.Helper()

	if os.Geteuid() != 0 {
		t.Skip("not running as root; skipping the real useradd/chown contract tests")
	}

	for _, bin := range []string{"useradd", "userdel"} {
		if _, err := exec.LookPath(bin); err != nil {
			t.Skipf("%s is not installed on PATH; skipping the real useradd/chown contract tests", bin)
		}
	}
}

func newTestUUID() string {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		panic(fmt.Sprintf("generating test UUID: %v", err))
	}

	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80

	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16])
}

func newOp(operation protocol.Operation, resourceID string, payload map[string]any) protocol.OperationEnvelope {
	raw, err := json.Marshal(payload)
	if err != nil {
		panic(err)
	}

	now := time.Now().UTC()

	return protocol.OperationEnvelope{
		ProtocolVersion:     "1",
		Capability:          "files.manager.v1",
		Operation:           operation,
		ResourceID:          resourceID,
		DesiredStateVersion: 1,
		IdempotencyKey:      newTestUUID(),
		CorrelationID:       newTestUUID(),
		Deadline:            now.Add(10 * time.Second),
		IssuedAt:            now,
		RequestDigest:       "sha256:" + strings.Repeat("0", 64),
		Payload:             raw,
	}
}

// setupDocroot builds AccountsRoot/<username>/domains/<resourceID>/public
// by hand, without ever involving a real OS identity -- correct for every
// test here except the real-chown create test, since Observe/Update/Delete
// never look up the account's own uid/gid at all.
func setupDocroot(t *testing.T, username, resourceID string) (accountsRoot, docroot string) {
	t.Helper()

	accountsRoot = t.TempDir()
	docroot = filepath.Join(accountsRoot, username, "domains", resourceID, "public")

	if err := os.MkdirAll(docroot, 0o755); err != nil {
		t.Fatalf("creating docroot: %v", err)
	}

	return accountsRoot, docroot
}

func assertOwnedBy(t *testing.T, path string, wantUID, wantGID int) {
	t.Helper()

	info, err := os.Stat(path)
	if err != nil {
		t.Fatalf("stat %s: %v", path, err)
	}

	stat, ok := info.Sys().(*syscall.Stat_t)
	if !ok {
		t.Fatal("expected a *syscall.Stat_t from info.Sys()")
	}

	if got := int(stat.Uid); got != wantUID {
		t.Fatalf("%s owner uid = %d, want %d", path, got, wantUID)
	}
	if got := int(stat.Gid); got != wantGID {
		t.Fatalf("%s owner gid = %d, want %d", path, got, wantGID)
	}
}

func requireStatus(t *testing.T, label string, result protocol.ResultEnvelope, err error, want protocol.Status) {
	t.Helper()

	if err != nil {
		t.Fatalf("%s: Apply returned an error (no verdict reached): %v", label, err)
	}

	if result.Status != want {
		t.Fatalf("%s: status = %q, want %q (errors: %+v)", label, result.Status, want, result.Errors)
	}
}

func TestParsePayloadRejectsAbsolutePath(t *testing.T) {
	_, docroot := setupDocroot(t, "lesta-t1", newTestUUID())
	cfg := files.Config{AccountsRoot: filepath.Dir(filepath.Dir(filepath.Dir(docroot)))}
	capability := files.New(cfg)

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, newTestUUID(), map[string]any{
		"account_username": "lesta-t1",
		"path":             "/etc/passwd",
	}))
	requireStatus(t, "absolute path", result, err, protocol.StatusRejected)

	if result.Errors[0].Code != "invalid_path" {
		t.Fatalf("expected code invalid_path, got %q", result.Errors[0].Code)
	}
}

func TestParsePayloadRejectsDotDotSegment(t *testing.T) {
	cfg := files.Config{AccountsRoot: t.TempDir()}
	capability := files.New(cfg)

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, newTestUUID(), map[string]any{
		"account_username": "lesta-t1",
		"path":             "../../etc/passwd",
	}))
	requireStatus(t, "dot-dot traversal", result, err, protocol.StatusRejected)

	if result.Errors[0].Code != "invalid_path" {
		t.Fatalf("expected code invalid_path, got %q", result.Errors[0].Code)
	}
}

func TestParsePayloadRejectsInvalidAccountUsername(t *testing.T) {
	cfg := files.Config{AccountsRoot: t.TempDir()}
	capability := files.New(cfg)

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, newTestUUID(), map[string]any{
		"account_username": "Not Valid!",
		"path":             "",
	}))
	requireStatus(t, "invalid account_username", result, err, protocol.StatusRejected)

	if result.Errors[0].Code != "invalid_account_username" {
		t.Fatalf("expected code invalid_account_username, got %q", result.Errors[0].Code)
	}
}

func TestParsePayloadRejectsUnknownFields(t *testing.T) {
	cfg := files.Config{AccountsRoot: t.TempDir()}
	capability := files.New(cfg)

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, newTestUUID(), map[string]any{
		"account_username": "lesta-t1",
		"path":             "",
		"unexpected_field": "value",
	}))

	if err == nil {
		t.Fatalf("expected a Go error for an unknown payload field, got a result: %+v", result)
	}
}

func TestObserveListsADirectory(t *testing.T) {
	username := "lesta-t1"
	resourceID := newTestUUID()
	accountsRoot, docroot := setupDocroot(t, username, resourceID)

	if err := os.WriteFile(filepath.Join(docroot, "index.html"), []byte("hello"), 0o644); err != nil {
		t.Fatalf("seeding a file: %v", err)
	}
	if err := os.Mkdir(filepath.Join(docroot, "assets"), 0o755); err != nil {
		t.Fatalf("seeding a directory: %v", err)
	}

	capability := files.New(files.Config{AccountsRoot: accountsRoot})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, resourceID, map[string]any{
		"account_username": username,
		"path":             "",
	}))
	requireStatus(t, "observe directory", result, err, protocol.StatusApplied)

	var data struct {
		Type    string `json:"type"`
		Entries []struct {
			Name string `json:"name"`
			Type string `json:"type"`
		} `json:"entries"`
	}
	if err := json.Unmarshal(result.Data, &data); err != nil {
		t.Fatalf("decoding observe data: %v", err)
	}

	if data.Type != "directory" {
		t.Fatalf("expected type=directory, got %q", data.Type)
	}
	if len(data.Entries) != 2 {
		t.Fatalf("expected 2 entries, got %d: %+v", len(data.Entries), data.Entries)
	}
}

func TestObserveReadsFileContent(t *testing.T) {
	username := "lesta-t1"
	resourceID := newTestUUID()
	accountsRoot, docroot := setupDocroot(t, username, resourceID)

	content := "<?php echo 'hi'; ?>"
	if err := os.WriteFile(filepath.Join(docroot, "index.php"), []byte(content), 0o644); err != nil {
		t.Fatalf("seeding a file: %v", err)
	}

	capability := files.New(files.Config{AccountsRoot: accountsRoot})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, resourceID, map[string]any{
		"account_username": username,
		"path":             "index.php",
	}))
	requireStatus(t, "observe file", result, err, protocol.StatusApplied)

	var data struct {
		Type          string `json:"type"`
		ContentBase64 string `json:"content_base64"`
	}
	if err := json.Unmarshal(result.Data, &data); err != nil {
		t.Fatalf("decoding observe data: %v", err)
	}

	if data.Type != "file" {
		t.Fatalf("expected type=file, got %q", data.Type)
	}

	decoded, err := base64.StdEncoding.DecodeString(data.ContentBase64)
	if err != nil {
		t.Fatalf("decoding content_base64: %v", err)
	}
	if string(decoded) != content {
		t.Fatalf("content = %q, want %q", decoded, content)
	}
}

func TestObserveRejectsASymlinkEscapingTheDocroot(t *testing.T) {
	username := "lesta-t1"
	resourceID := newTestUUID()
	accountsRoot, docroot := setupDocroot(t, username, resourceID)

	outside := filepath.Join(t.TempDir(), "secret.txt")
	if err := os.WriteFile(outside, []byte("should never be reachable"), 0o644); err != nil {
		t.Fatalf("seeding the outside-docroot file: %v", err)
	}
	if err := os.Symlink(outside, filepath.Join(docroot, "escape.txt")); err != nil {
		t.Fatalf("creating the escaping symlink: %v", err)
	}

	capability := files.New(files.Config{AccountsRoot: accountsRoot})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, resourceID, map[string]any{
		"account_username": username,
		"path":             "escape.txt",
	}))
	requireStatus(t, "symlink escape", result, err, protocol.StatusRejected)

	if result.Errors[0].Code != "path_escapes_docroot" {
		t.Fatalf("expected code path_escapes_docroot, got %q", result.Errors[0].Code)
	}
}

func TestUpdateOverwritesFileContent(t *testing.T) {
	username := "lesta-t1"
	resourceID := newTestUUID()
	accountsRoot, docroot := setupDocroot(t, username, resourceID)

	path := filepath.Join(docroot, "index.html")
	if err := os.WriteFile(path, []byte("old"), 0o644); err != nil {
		t.Fatalf("seeding a file: %v", err)
	}

	capability := files.New(files.Config{AccountsRoot: accountsRoot})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationUpdate, resourceID, map[string]any{
		"account_username": username,
		"path":             "index.html",
		"content_base64":   base64.StdEncoding.EncodeToString([]byte("new content")),
	}))
	requireStatus(t, "update overwrite", result, err, protocol.StatusApplied)

	got, err := os.ReadFile(path)
	if err != nil {
		t.Fatalf("reading back the file: %v", err)
	}
	if string(got) != "new content" {
		t.Fatalf("content = %q, want %q", got, "new content")
	}
}

func TestUpdateRenamesAFile(t *testing.T) {
	username := "lesta-t1"
	resourceID := newTestUUID()
	accountsRoot, docroot := setupDocroot(t, username, resourceID)

	if err := os.WriteFile(filepath.Join(docroot, "old.html"), []byte("content"), 0o644); err != nil {
		t.Fatalf("seeding a file: %v", err)
	}

	capability := files.New(files.Config{AccountsRoot: accountsRoot})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationUpdate, resourceID, map[string]any{
		"account_username": username,
		"path":             "old.html",
		"new_path":         "new.html",
	}))
	requireStatus(t, "update rename", result, err, protocol.StatusApplied)

	if _, err := os.Stat(filepath.Join(docroot, "old.html")); !os.IsNotExist(err) {
		t.Fatalf("expected old.html to no longer exist, stat error: %v", err)
	}
	if _, err := os.Stat(filepath.Join(docroot, "new.html")); err != nil {
		t.Fatalf("expected new.html to exist: %v", err)
	}
}

func TestDeleteRemovesAFile(t *testing.T) {
	username := "lesta-t1"
	resourceID := newTestUUID()
	accountsRoot, docroot := setupDocroot(t, username, resourceID)

	if err := os.WriteFile(filepath.Join(docroot, "gone.html"), []byte("x"), 0o644); err != nil {
		t.Fatalf("seeding a file: %v", err)
	}

	capability := files.New(files.Config{AccountsRoot: accountsRoot})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationDelete, resourceID, map[string]any{
		"account_username": username,
		"path":             "gone.html",
	}))
	requireStatus(t, "delete file", result, err, protocol.StatusApplied)

	if _, err := os.Stat(filepath.Join(docroot, "gone.html")); !os.IsNotExist(err) {
		t.Fatalf("expected gone.html to no longer exist, stat error: %v", err)
	}
}

func TestDeleteRefusesANonEmptyDirectoryWithoutRecursive(t *testing.T) {
	username := "lesta-t1"
	resourceID := newTestUUID()
	accountsRoot, docroot := setupDocroot(t, username, resourceID)

	dir := filepath.Join(docroot, "stuff")
	if err := os.Mkdir(dir, 0o755); err != nil {
		t.Fatalf("seeding a directory: %v", err)
	}
	if err := os.WriteFile(filepath.Join(dir, "inside.txt"), []byte("x"), 0o644); err != nil {
		t.Fatalf("seeding a file inside it: %v", err)
	}

	capability := files.New(files.Config{AccountsRoot: accountsRoot})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationDelete, resourceID, map[string]any{
		"account_username": username,
		"path":             "stuff",
	}))
	requireStatus(t, "delete non-empty dir without recursive", result, err, protocol.StatusRejected)

	if _, err := os.Stat(dir); err != nil {
		t.Fatalf("expected the directory to still exist: %v", err)
	}
}

func TestDeleteRecursivelyRemovesANonEmptyDirectory(t *testing.T) {
	username := "lesta-t1"
	resourceID := newTestUUID()
	accountsRoot, docroot := setupDocroot(t, username, resourceID)

	dir := filepath.Join(docroot, "stuff")
	if err := os.Mkdir(dir, 0o755); err != nil {
		t.Fatalf("seeding a directory: %v", err)
	}
	if err := os.WriteFile(filepath.Join(dir, "inside.txt"), []byte("x"), 0o644); err != nil {
		t.Fatalf("seeding a file inside it: %v", err)
	}

	capability := files.New(files.Config{AccountsRoot: accountsRoot})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationDelete, resourceID, map[string]any{
		"account_username": username,
		"path":             "stuff",
		"recursive":        true,
	}))
	requireStatus(t, "delete recursive", result, err, protocol.StatusApplied)

	if _, err := os.Stat(dir); !os.IsNotExist(err) {
		t.Fatalf("expected the directory to no longer exist, stat error: %v", err)
	}
}

func TestDeleteRefusesTheDocrootItself(t *testing.T) {
	username := "lesta-t1"
	resourceID := newTestUUID()
	accountsRoot, docroot := setupDocroot(t, username, resourceID)

	capability := files.New(files.Config{AccountsRoot: accountsRoot})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationDelete, resourceID, map[string]any{
		"account_username": username,
		"path":             "",
		"recursive":        true,
	}))
	requireStatus(t, "delete docroot itself", result, err, protocol.StatusRejected)

	if _, err := os.Stat(docroot); err != nil {
		t.Fatalf("expected the docroot to still exist: %v", err)
	}
}

// TestCreateFileAndDirectoryAreOwnedByTheRealAccountIdentity proves the one
// real root-only action this capability needs: a newly-created file or
// directory is explicitly chowned to the account's own uid/gid, never left
// root-owned, the exact "identical ownership to the SFTP path" requirement
// this whole capability exists to uphold.
func TestCreateFileAndDirectoryAreOwnedByTheRealAccountIdentity(t *testing.T) {
	requireRootAndUseradd(t)

	username := "lestafiles" + newTestUUID()[:8]
	if out, err := exec.Command("useradd", "--system", "--no-create-home", "--shell", "/usr/sbin/nologin", username).CombinedOutput(); err != nil {
		t.Fatalf("creating real test user %s: %v: %s", username, err, out)
	}
	t.Cleanup(func() { _ = exec.Command("userdel", username).Run() })

	u, err := user.Lookup(username)
	if err != nil {
		t.Fatalf("looking up the just-created user: %v", err)
	}
	wantUID, _ := strconv.Atoi(u.Uid)
	wantGID, _ := strconv.Atoi(u.Gid)

	resourceID := newTestUUID()
	accountsRoot, docroot := setupDocroot(t, username, resourceID)

	capability := files.New(files.Config{AccountsRoot: accountsRoot})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, map[string]any{
		"account_username": username,
		"path":             "index.html",
		"content_base64":   base64.StdEncoding.EncodeToString([]byte("hello")),
	}))
	requireStatus(t, "create file", result, err, protocol.StatusApplied)

	assertOwnedBy(t, filepath.Join(docroot, "index.html"), wantUID, wantGID)

	result, err = capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, map[string]any{
		"account_username": username,
		"path":             "uploads",
		"is_directory":     true,
	}))
	requireStatus(t, "create directory", result, err, protocol.StatusApplied)

	assertOwnedBy(t, filepath.Join(docroot, "uploads"), wantUID, wantGID)
}

func TestCreateRejectsAnAlreadyExistingPath(t *testing.T) {
	requireRootAndUseradd(t)

	username := "lestafiles" + newTestUUID()[:8]
	if out, err := exec.Command("useradd", "--system", "--no-create-home", "--shell", "/usr/sbin/nologin", username).CombinedOutput(); err != nil {
		t.Fatalf("creating real test user %s: %v: %s", username, err, out)
	}
	t.Cleanup(func() { _ = exec.Command("userdel", username).Run() })

	resourceID := newTestUUID()
	accountsRoot, docroot := setupDocroot(t, username, resourceID)

	if err := os.WriteFile(filepath.Join(docroot, "exists.html"), []byte("x"), 0o644); err != nil {
		t.Fatalf("seeding a file: %v", err)
	}

	capability := files.New(files.Config{AccountsRoot: accountsRoot})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, map[string]any{
		"account_username": username,
		"path":             "exists.html",
	}))
	requireStatus(t, "create over an existing path", result, err, protocol.StatusRejected)

	if result.Errors[0].Code != "already_exists" {
		t.Fatalf("expected code already_exists, got %q", result.Errors[0].Code)
	}
}
