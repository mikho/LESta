package backup_test

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/backup"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// plainBackupOf makes a backup of the harness account and returns it as the plain
// tar.gz a download produces.
func plainBackupOf(t *testing.T, h *accountHarness, parts ...string) []byte {
	t.Helper()

	panel := newFakePanel(t)
	capability := backup.New(h.cfg)
	key := newTestEncryptionKey()
	resourceID := newTestUUID()

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, newTestUUID(), map[string]any{"encryption_key": key, "account": h.account(parts...)}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	path := filepath.Join(h.cfg.ArtifactsRoot, "accounts", h.username, resourceID+".acct.enc")

	downloaded, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, resourceID, newTestUUID(), map[string]any{
		"encryption_key": key, "artifact_path": path, "account": h.account(parts...), "upload_url": panel.url(),
	}))
	requireStatus(t, "download", downloaded, err, protocol.StatusApplied)

	return panel.body.Bytes()
}

func serveImport(t *testing.T, body []byte) string {
	t.Helper()

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if r.Method != http.MethodGet {
			http.Error(w, "method", http.StatusMethodNotAllowed)

			return
		}

		_, _ = w.Write(body)
	}))
	t.Cleanup(server.Close)

	return server.URL + "/agent/v1/account-backup-imports/" + strings.Repeat("cd", 32)
}

func tarGz(t *testing.T, files map[string]string, order []string) []byte {
	t.Helper()

	var buf bytes.Buffer

	gz := gzip.NewWriter(&buf)
	tw := tar.NewWriter(gz)

	for _, name := range order {
		content := files[name]
		if err := tw.WriteHeader(&tar.Header{Name: name, Mode: 0o644, Size: int64(len(content)), Typeflag: tar.TypeReg}); err != nil {
			t.Fatal(err)
		}

		_, _ = tw.Write([]byte(content))
	}

	_ = tw.Close()
	_ = gz.Close()

	return buf.Bytes()
}

func TestAccountImportFromTheControlPlaneThenRestore(t *testing.T) {
	h := newAccountHarness(t)
	plain := plainBackupOf(t, h, "files", "databases")

	capability := backup.New(h.cfg)
	ctx := context.Background()
	key := newTestEncryptionKey()
	resourceID := newTestUUID()

	imported, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), map[string]any{
		"encryption_key": key, "account": h.account("files", "databases", "mail"), "source_url": serveImport(t, plain),
	}))
	requireStatus(t, "import", imported, err, protocol.StatusApplied)

	if !strings.Contains(imported.Data.String(), `"parts":["files","databases"]`) {
		t.Errorf("the parts must come from the file, not the request: %s", imported.Data.String())
	}

	path := filepath.Join(h.cfg.ArtifactsRoot, "accounts", h.username, resourceID+".acct.enc")

	sealed, _ := os.ReadFile(path)
	if strings.Contains(string(sealed), "original") {
		t.Errorf("an imported backup must be sealed like any other")
	}

	if leftovers, _ := filepath.Glob(filepath.Join(h.cfg.ArtifactsRoot, "accounts", h.username, ".import-*")); len(leftovers) != 0 {
		t.Errorf("the temporary file must be removed: %v", leftovers)
	}

	if err := os.WriteFile(filepath.Join(h.siteDir, "index.html"), []byte("<h1>defaced</h1>"), 0o644); err != nil {
		t.Fatal(err)
	}

	restored, err := capability.Apply(ctx, newOp(protocol.OperationRestore, resourceID, newTestUUID(), map[string]any{
		"encryption_key": key, "artifact_path": path, "account": h.account("files"),
	}))
	requireStatus(t, "restore", restored, err, protocol.StatusApplied)

	if got, _ := os.ReadFile(filepath.Join(h.siteDir, "index.html")); string(got) != "<h1>original</h1>" {
		t.Errorf("the imported backup did not restore: %q", got)
	}
}

func TestAccountImportRefusesFilesThatAreNotThisAccountsBackup(t *testing.T) {
	h := newAccountHarness(t)
	manifest := func(username string) string {
		return `{"version":1,"username":"` + username + `","parts":["files"],"created":"2026-10-09T00:00:00Z"}`
	}

	cases := map[string][]byte{
		"not gzip":          []byte("hello"),
		"no manifest first": tarGz(t, map[string]string{"files/x/a": "a", "manifest.json": manifest(h.username)}, []string{"files/x/a", "manifest.json"}),
		"another account":   tarGz(t, map[string]string{"manifest.json": manifest("someoneelse"), "files/x/a": "a"}, []string{"manifest.json", "files/x/a"}),
		"unsafe name":       tarGz(t, map[string]string{"manifest.json": manifest(h.username), "files/x/../../etc/passwd": "x"}, []string{"manifest.json", "files/x/../../etc/passwd"}),
		"nothing in it":     tarGz(t, map[string]string{"manifest.json": manifest(h.username)}, []string{"manifest.json"}),
		"bad manifest":      tarGz(t, map[string]string{"manifest.json": "{}"}, []string{"manifest.json"}),
	}

	capability := backup.New(h.cfg)

	for name, body := range cases {
		resourceID := newTestUUID()

		result, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, newTestUUID(), map[string]any{
			"encryption_key": newTestEncryptionKey(), "account": h.account("files"), "source_url": serveImport(t, body),
		}))
		requireStatus(t, name, result, err, protocol.StatusFailed)
		requireErrorCode(t, name, result, "import_invalid")

		if _, err := os.Stat(filepath.Join(h.cfg.ArtifactsRoot, "accounts", h.username, resourceID+".acct.enc")); err == nil {
			t.Errorf("%s: nothing may be kept for a refused file", name)
		}
	}
}

func TestAccountImportRefusesABadSourceAddress(t *testing.T) {
	h := newAccountHarness(t)
	capability := backup.New(h.cfg)

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), map[string]any{
		"encryption_key": newTestEncryptionKey(), "account": h.account("files"), "source_url": "http://127.0.0.1:1/etc/passwd",
	}))
	requireStatus(t, "bad address", result, err, protocol.StatusFailed)
	requireErrorCode(t, "bad address", result, "import_fetch_failed")
}

func TestAccountImportFromTheAccountsOwnStorage(t *testing.T) {
	h := newAccountHarness(t)
	plain := plainBackupOf(t, h, "files")

	storage := httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		auth := r.Header.Get("Authorization")

		switch {
		case r.Method != http.MethodGet || r.URL.Path != "/my-backups/lesta/acct/one.tar.gz":
			http.Error(w, "not found", http.StatusNotFound)
		case !strings.HasPrefix(auth, "AWS4-HMAC-SHA256 Credential=TESTACCESSKEY/") || !strings.Contains(auth, "SignedHeaders=host;x-amz-content-sha256;x-amz-date"):
			http.Error(w, "bad authorization", http.StatusForbidden)
		default:
			_, _ = w.Write(plain)
		}
	}))
	t.Cleanup(storage.Close)

	cfg := h.cfg
	cfg.StorageClient = storage.Client()
	capability := backup.New(cfg)
	destination := map[string]any{"endpoint": storage.URL, "region": "eu-west-1", "bucket": "my-backups", "access_key": "TESTACCESSKEY", "secret_key": "test-secret-key/1234"}

	imported, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), map[string]any{
		"encryption_key": newTestEncryptionKey(), "account": h.account("files"), "destination": destination, "object_key": "lesta/acct/one.tar.gz",
	}))
	requireStatus(t, "import from storage", imported, err, protocol.StatusApplied)

	missing, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), map[string]any{
		"encryption_key": newTestEncryptionKey(), "account": h.account("files"), "destination": destination, "object_key": "lesta/acct/missing.tar.gz",
	}))
	requireStatus(t, "missing object", missing, err, protocol.StatusFailed)
	requireErrorCode(t, "missing object", missing, "import_fetch_failed")
}
