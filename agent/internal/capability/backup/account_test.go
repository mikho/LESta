package backup_test

import (
	"archive/tar"
	"bytes"
	"compress/gzip"
	"context"
	"crypto/rand"
	"encoding/json"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"os/user"
	"path/filepath"
	"strconv"
	"strings"
	"sync"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/backup"
	"github.com/mikho/LESta/agent/internal/protocol"
)

type accountHarness struct {
	cfg        backup.Config
	username   string
	siteID     string
	siteDir    string
	mailDir    string
	clientSink string
}

// newAccountHarness builds a node layout in temp folders with the current user
// as the account, a fake database dump and client, one site and one mailbox.
func newAccountHarness(t *testing.T) *accountHarness {
	t.Helper()

	current, err := user.Current()
	if err != nil {
		t.Fatalf("looking up the current user: %v", err)
	}

	if !strings.HasPrefix(current.Username, "") || len(current.Username) == 0 {
		t.Skip("no current user")
	}

	root := t.TempDir()
	h := &accountHarness{username: current.Username, siteID: newTestUUID()}

	h.cfg = backup.Config{
		ArtifactsRoot: filepath.Join(root, "backups"),
		AccountsRoot:  filepath.Join(root, "accounts"),
		VMailRoot:     filepath.Join(root, "vmail"),
		DumpBinary:    writeScript(t, root, "dump", "#!/bin/sh\nfor last; do :; done\necho \"CREATE DATABASE IF NOT EXISTS $last; -- dumped\"\n"),
	}

	h.clientSink = filepath.Join(root, "client-input.sql")
	h.cfg.ClientBinary = writeScript(t, root, "client", "#!/bin/sh\ncat >> "+h.clientSink+"\n")

	socket := filepath.Join(root, "tenant.sock")
	if err := os.WriteFile(socket, nil, 0o600); err != nil {
		t.Fatal(err)
	}

	h.cfg.DatabaseDumpSockets = map[string]string{"database.tenant.v1": socket}

	h.siteDir = filepath.Join(h.cfg.AccountsRoot, h.username, "domains", h.siteID, "public")
	h.mailDir = filepath.Join(h.cfg.VMailRoot, "example.com")

	for name, content := range map[string]string{
		filepath.Join(h.siteDir, "index.html"):          "<h1>original</h1>",
		filepath.Join(h.siteDir, "wp-content", "a.txt"): "kept",
		filepath.Join(h.mailDir, "alice", "cur", "1"):   "a message",
	} {
		if err := os.MkdirAll(filepath.Dir(name), 0o755); err != nil {
			t.Fatal(err)
		}

		if err := os.WriteFile(name, []byte(content), 0o644); err != nil {
			t.Fatal(err)
		}
	}

	return h
}

func writeScript(t *testing.T, dir, name, body string) string {
	t.Helper()

	p := filepath.Join(dir, name)
	if err := os.WriteFile(p, []byte(body), 0o755); err != nil {
		t.Fatal(err)
	}

	return p
}

func (h *accountHarness) account(parts ...string) map[string]any {
	return map[string]any{
		"username":      h.username,
		"web_resources": []string{h.siteID},
		"mail_domains":  []string{"example.com"},
		"databases":     []string{"shopdb"},
		"parts":         parts,
	}
}

func TestAccountBackupAndRestoreThroughTheCapability(t *testing.T) {
	h := newAccountHarness(t)
	capability := backup.New(h.cfg)
	ctx := context.Background()

	key := newTestEncryptionKey()
	resourceID := newTestUUID()

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), map[string]any{
		"encryption_key": key,
		"account":        h.account("files", "databases", "mail"),
	}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	var data struct {
		Parts        []string `json:"parts"`
		SizeBytes    int64    `json:"size_bytes"`
		Checksum     string   `json:"checksum"`
		ArtifactPath string   `json:"artifact_path"`
	}
	if err := json.Unmarshal(created.Data, &data); err != nil {
		t.Fatalf("decoding the result data: %v", err)
	}

	wantPath := filepath.Join(h.cfg.ArtifactsRoot, "accounts", h.username, resourceID+".acct.enc")

	if data.ArtifactPath != wantPath || data.SizeBytes <= 0 || !strings.HasPrefix(data.Checksum, "sha256:") || strings.Join(data.Parts, ",") != "files,databases,mail" {
		t.Fatalf("unexpected result data: %+v", data)
	}

	if info, err := os.Stat(wantPath); err != nil || info.Mode().Perm() != 0o600 {
		t.Errorf("the artifact must be a private file: %v %v", info, err)
	}

	if leftovers, _ := filepath.Glob(filepath.Join(h.cfg.ArtifactsRoot, "accounts", h.username, ".dump-*")); len(leftovers) != 0 {
		t.Errorf("the temporary database dumps must be removed: %v", leftovers)
	}

	sealed, _ := os.ReadFile(wantPath)
	if strings.Contains(string(sealed), "original") || strings.Contains(string(sealed), "a message") {
		t.Errorf("the artifact must be encrypted")
	}

	// Change the live data, then restore the files and the database but not the mail.
	if err := os.WriteFile(filepath.Join(h.siteDir, "index.html"), []byte("<h1>defaced</h1>"), 0o644); err != nil {
		t.Fatal(err)
	}

	if err := os.WriteFile(filepath.Join(h.siteDir, "new-file.txt"), []byte("created later"), 0o644); err != nil {
		t.Fatal(err)
	}

	if err := os.Remove(filepath.Join(h.siteDir, "wp-content", "a.txt")); err != nil {
		t.Fatal(err)
	}

	if err := os.WriteFile(filepath.Join(h.mailDir, "alice", "cur", "1"), []byte("changed mail"), 0o644); err != nil {
		t.Fatal(err)
	}

	restored, err := capability.Apply(ctx, newOp(protocol.OperationRestore, resourceID, newTestUUID(), map[string]any{
		"encryption_key": key,
		"artifact_path":  wantPath,
		"account":        h.account("files", "databases"),
	}))
	requireStatus(t, "restore", restored, err, protocol.StatusApplied)

	if got, _ := os.ReadFile(filepath.Join(h.siteDir, "index.html")); string(got) != "<h1>original</h1>" {
		t.Errorf("the site was not restored: %q", got)
	}

	if got, _ := os.ReadFile(filepath.Join(h.siteDir, "wp-content", "a.txt")); string(got) != "kept" {
		t.Errorf("a deleted file must come back: %q", got)
	}

	if got, _ := os.ReadFile(filepath.Join(h.siteDir, "new-file.txt")); string(got) != "created later" {
		t.Errorf("a file created after the backup is left alone: %q", got)
	}

	if got, _ := os.ReadFile(filepath.Join(h.mailDir, "alice", "cur", "1")); string(got) != "changed mail" {
		t.Errorf("mail was not selected and must be untouched: %q", got)
	}

	if sql, _ := os.ReadFile(h.clientSink); !strings.Contains(string(sql), "CREATE DATABASE IF NOT EXISTS shopdb") {
		t.Errorf("the database dump was not loaded: %q", sql)
	}

	// A replay of the same create is answered from the artifact, not redone.
	replay, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), map[string]any{
		"encryption_key": key,
		"account":        h.account("files", "databases", "mail"),
	}))
	requireStatus(t, "replay", replay, err, protocol.StatusAlreadyApplied)

	deleted, err := capability.Apply(ctx, newOp(protocol.OperationDelete, resourceID, newTestUUID(), map[string]any{"artifact_path": wantPath}))
	requireStatus(t, "delete", deleted, err, protocol.StatusApplied)

	if _, err := os.Stat(wantPath); err == nil {
		t.Errorf("the artifact must be removed")
	}
}

func TestAccountBackupHonoursTheSelectedParts(t *testing.T) {
	h := newAccountHarness(t)
	capability := backup.New(h.cfg)

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), map[string]any{
		"encryption_key": newTestEncryptionKey(),
		"account":        h.account("files"),
	}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	var data struct {
		Parts []string `json:"parts"`
	}
	_ = json.Unmarshal(created.Data, &data)

	if len(data.Parts) != 1 || data.Parts[0] != "files" {
		t.Errorf("only the files part was selected: %v", data.Parts)
	}
}

func TestAccountPayloadsAreValidatedBeforeAnythingHappens(t *testing.T) {
	h := newAccountHarness(t)
	capability := backup.New(h.cfg)

	for name, mutate := range map[string]func(a map[string]any){
		"a path as the username":   func(a map[string]any) { a["username"] = "../../etc" },
		"a path as a site id":      func(a map[string]any) { a["web_resources"] = []string{"../../etc/passwd"} },
		"an injection as a domain": func(a map[string]any) { a["mail_domains"] = []string{"example.com/../../x"} },
		"a quote in a database":    func(a map[string]any) { a["databases"] = []string{"db`; DROP DATABASE mysql; --"} },
		"an unknown part":          func(a map[string]any) { a["parts"] = []string{"everything"} },
		"no parts":                 func(a map[string]any) { a["parts"] = []string{} },
	} {
		account := h.account("files")
		mutate(account)

		result, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), map[string]any{
			"encryption_key": newTestEncryptionKey(),
			"account":        account,
		}))
		requireStatus(t, name, result, err, protocol.StatusRejected)
	}

	if entries, _ := os.ReadDir(filepath.Join(h.cfg.ArtifactsRoot, "accounts")); len(entries) != 0 {
		t.Errorf("a rejected request must create nothing")
	}
}

func TestAccountRestoreRefusesAnArtifactOfAnotherFolderAndAWrongKey(t *testing.T) {
	h := newAccountHarness(t)
	capability := backup.New(h.cfg)
	ctx := context.Background()

	key := newTestEncryptionKey()
	resourceID := newTestUUID()

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), map[string]any{"encryption_key": key, "account": h.account("files")}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	path := filepath.Join(h.cfg.ArtifactsRoot, "accounts", h.username, resourceID+".acct.enc")

	other := filepath.Join(h.cfg.ArtifactsRoot, "accounts", "someone-else")
	if err := os.MkdirAll(other, 0o750); err != nil {
		t.Fatal(err)
	}

	moved := filepath.Join(other, resourceID+".acct.enc")
	if err := os.Link(path, moved); err != nil {
		t.Fatal(err)
	}

	result, err := capability.Apply(ctx, newOp(protocol.OperationRestore, resourceID, newTestUUID(), map[string]any{"encryption_key": key, "artifact_path": moved, "account": h.account("files")}))
	requireStatus(t, "another account's folder", result, err, protocol.StatusFailed)
	requireErrorCode(t, "another account's folder", result, "artifact_path_outside_root")

	result, err = capability.Apply(ctx, newOp(protocol.OperationRestore, resourceID, newTestUUID(), map[string]any{"encryption_key": newTestEncryptionKey(), "artifact_path": path, "account": h.account("files")}))
	requireStatus(t, "wrong key", result, err, protocol.StatusFailed)
	requireErrorCode(t, "wrong key", result, "restore_failed")
}

func TestAccountBackupFailsCleanlyWhenTheAccountIsTooLarge(t *testing.T) {
	h := newAccountHarness(t)
	h.cfg.MaxAccountSourceBytes = 8
	capability := backup.New(h.cfg)

	resourceID := newTestUUID()

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, newTestUUID(), map[string]any{
		"encryption_key": newTestEncryptionKey(),
		"account":        h.account("files"),
	}))
	requireStatus(t, "too large", result, err, protocol.StatusFailed)
	requireErrorCode(t, "too large", result, "backup_too_large")

	if leftovers, _ := filepath.Glob(filepath.Join(h.cfg.ArtifactsRoot, "accounts", h.username, "*")); len(leftovers) != 0 {
		t.Errorf("a failed backup must leave no file behind: %v", leftovers)
	}
}

// fakePanel is a stand-in for the control plane's one-time upload address: it
// accepts chunks only at the expected offset and records what arrived.
type fakePanel struct {
	server  *httptest.Server
	mu      sync.Mutex
	body    bytes.Buffer
	final   bool
	refuse  bool
	chunks  int
	offsets []int64
}

func newFakePanel(t *testing.T) *fakePanel {
	t.Helper()

	p := &fakePanel{}

	p.server = httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		p.mu.Lock()
		defer p.mu.Unlock()

		if r.Method != http.MethodPut || p.refuse {
			http.Error(w, "refused", http.StatusForbidden)

			return
		}

		offset, _ := strconv.ParseInt(r.URL.Query().Get("offset"), 10, 64)
		if offset != int64(p.body.Len()) {
			http.Error(w, "wrong offset", http.StatusConflict)

			return
		}

		chunk, _ := io.ReadAll(r.Body)
		p.body.Write(chunk)
		p.chunks++
		p.offsets = append(p.offsets, offset)

		if r.URL.Query().Get("final") == "1" {
			p.final = true
		}

		w.WriteHeader(http.StatusNoContent)
	}))

	t.Cleanup(p.server.Close)

	return p
}

func (p *fakePanel) url() string {
	return p.server.URL + "/agent/v1/account-backup-uploads/" + strings.Repeat("ab", 32)
}

func TestAccountBackupDownloadStreamsAPlainTarGzToTheUploadAddress(t *testing.T) {
	h := newAccountHarness(t)

	// A site big enough for several upload chunks.
	big := make([]byte, 6<<20)
	_, _ = rand.Read(big)

	if err := os.WriteFile(filepath.Join(h.siteDir, "random.bin"), big, 0o644); err != nil {
		t.Fatal(err)
	}

	panel := newFakePanel(t)
	capability := backup.New(h.cfg)
	ctx := context.Background()

	key := newTestEncryptionKey()
	resourceID := newTestUUID()

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), map[string]any{"encryption_key": key, "account": h.account("files", "databases")}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	path := filepath.Join(h.cfg.ArtifactsRoot, "accounts", h.username, resourceID+".acct.enc")

	downloaded, err := capability.Apply(ctx, newOp(protocol.OperationObserve, resourceID, newTestUUID(), map[string]any{
		"encryption_key": key, "artifact_path": path, "account": h.account("files", "databases"), "upload_url": panel.url(),
	}))
	requireStatus(t, "download", downloaded, err, protocol.StatusApplied)

	if !panel.final || panel.chunks < 3 {
		t.Errorf("expected several chunks and a final marker, got %d chunks final=%v", panel.chunks, panel.final)
	}

	// What arrived is a plain gzip tar holding the account's data.
	gz, err := gzip.NewReader(bytes.NewReader(panel.body.Bytes()))
	if err != nil {
		t.Fatalf("the download must be a gzip stream: %v", err)
	}

	names := map[string]bool{}
	tr := tar.NewReader(gz)

	for {
		header, err := tr.Next()
		if err != nil {
			break
		}

		names[header.Name] = true
	}

	if !names["manifest.json"] || !names["files/"+h.siteID+"/index.html"] || !names["databases/shopdb.sql"] || !names["files/"+h.siteID+"/random.bin"] {
		t.Errorf("unexpected archive content: %v", names)
	}

	var data struct {
		BytesSent int64 `json:"bytes_sent"`
	}
	_ = json.Unmarshal(downloaded.Data, &data)

	if data.BytesSent != int64(panel.body.Len()) {
		t.Errorf("bytes_sent %d does not match what arrived %d", data.BytesSent, panel.body.Len())
	}
}

func TestAccountBackupDownloadRefusesBadAddressesWrongKeyAndATamperedBackup(t *testing.T) {
	h := newAccountHarness(t)
	capability := backup.New(h.cfg)
	ctx := context.Background()

	key := newTestEncryptionKey()
	resourceID := newTestUUID()

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), map[string]any{"encryption_key": key, "account": h.account("files")}))
	requireStatus(t, "create", created, err, protocol.StatusApplied)

	path := filepath.Join(h.cfg.ArtifactsRoot, "accounts", h.username, resourceID+".acct.enc")
	panel := newFakePanel(t)

	download := func(key, url string) protocol.ResultEnvelope {
		result, err := capability.Apply(ctx, newOp(protocol.OperationObserve, resourceID, newTestUUID(), map[string]any{
			"encryption_key": key, "artifact_path": path, "account": h.account("files"), "upload_url": url,
		}))
		if err != nil {
			t.Fatalf("Apply: %v", err)
		}

		return result
	}

	for name, url := range map[string]string{
		"another path":        panel.server.URL + "/admin/secrets",
		"not an upload token": panel.server.URL + "/agent/v1/account-backup-uploads/short",
		"a file address":      "file:///etc/passwd",
		"with a query":        panel.url() + "?x=1",
	} {
		if result := download(key, url); result.Status != protocol.StatusFailed {
			t.Errorf("%s: expected a refusal, got %s", name, result.Status)
		}
	}

	if panel.chunks != 0 {
		t.Errorf("a refused address must receive nothing")
	}

	if result := download(newTestEncryptionKey(), panel.url()); result.Status != protocol.StatusFailed || panel.final {
		t.Errorf("the wrong key must fail and never mark the upload final: %s final=%v", result.Status, panel.final)
	}

	// Damage the end of the sealed file: the chunks that authenticate may be sent,
	// but the final marker never is.
	sealed, _ := os.ReadFile(path)
	sealed[len(sealed)-3] ^= 0xff

	if err := os.WriteFile(path, sealed, 0o600); err != nil {
		t.Fatal(err)
	}

	if result := download(key, panel.url()); result.Status != protocol.StatusFailed || panel.final {
		t.Errorf("a tampered backup must fail and never be marked complete: %s final=%v", result.Status, panel.final)
	}

	panel.refuse = true

	if result := download(key, panel.url()); result.Status != protocol.StatusFailed {
		t.Errorf("a refusing panel must fail the download")
	}
}
