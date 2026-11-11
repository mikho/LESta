package backup_test

import (
	"bytes"
	"context"
	"io"
	"net/http"
	"net/http/httptest"
	"os"
	"strconv"
	"sync"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/backup"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// uploadSink stands in for the control plane's upload address: it keeps the chunks in order, refuses a
// wrong offset like the real one and notes whether the final marker arrived.
type uploadSink struct {
	mu     sync.Mutex
	data   bytes.Buffer
	final  bool
	tokens []string
	server *httptest.Server
}

func newUploadSink(t *testing.T) *uploadSink {
	t.Helper()

	s := &uploadSink{}
	s.server = httptest.NewTLSServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		s.mu.Lock()
		defer s.mu.Unlock()

		offset, _ := strconv.Atoi(r.URL.Query().Get("offset"))
		if offset != s.data.Len() {
			http.Error(w, "wrong offset", http.StatusConflict)

			return
		}

		body, _ := io.ReadAll(r.Body)
		s.data.Write(body)
		s.tokens = append(s.tokens, r.Header.Get("Authorization"))

		if r.URL.Query().Get("final") == "1" {
			s.final = true
		}

		w.WriteHeader(http.StatusNoContent)
	}))

	t.Cleanup(s.server.Close)

	return s
}

func (s *uploadSink) url() string { return s.server.URL + "/agent/v1/backup-uploads" }

func TestAWholeNodeBackupIsStreamedToTheControlPlaneAsPlaintext(t *testing.T) {
	stateRoot := t.TempDir()
	big := bytes.Repeat([]byte("node download "), 1<<20) // 14 MiB, more than three upload chunks

	if err := os.WriteFile(stateRoot+"/big.conf", big, 0o644); err != nil {
		t.Fatal(err)
	}

	sink := newUploadSink(t)
	cfg := backup.Config{ArtifactsRoot: t.TempDir(), StateRoots: map[string]string{"web.nginx.v1": stateRoot}, UploadClient: sink.server.Client()}

	path, key := createMailArtifact(t, cfg)
	token := newTestEncryptionKey() // 64 hex characters, the shape of an upload token

	result, err := backup.New(cfg).Apply(context.Background(), newOp(protocol.OperationObserve, newTestUUID(), newTestUUID(),
		map[string]any{"artifact_path": path, "encryption_key": key, "upload_url": sink.url(), "upload_token": token}))
	requireStatus(t, "streamed download", result, err, protocol.StatusApplied)

	if !sink.final {
		t.Fatal("the final marker never arrived")
	}

	if got := readTarGzEntries(t, sink.data.Bytes())["web.nginx.v1/big.conf"]; got != string(big) {
		t.Fatalf("the plaintext archive that arrived does not hold the file (%d bytes)", len(got))
	}

	for _, auth := range sink.tokens {
		if auth != "Bearer "+token {
			t.Fatalf("every chunk must carry the token, got %q", auth)
		}
	}

	if bytes.Contains(result.Data, []byte("artifact_base64")) {
		t.Fatal("a streamed download must not also return the artifact inside the result")
	}
}

func TestADamagedBackupIsNeverMarkedCompleteByTheDownload(t *testing.T) {
	stateRoot := t.TempDir()
	if err := os.WriteFile(stateRoot+"/a.conf", bytes.Repeat([]byte("x"), 1<<20), 0o644); err != nil {
		t.Fatal(err)
	}

	sink := newUploadSink(t)
	cfg := backup.Config{ArtifactsRoot: t.TempDir(), StateRoots: map[string]string{"web.nginx.v1": stateRoot}, UploadClient: sink.server.Client()}

	path, key := createMailArtifact(t, cfg)

	sealed, err := os.ReadFile(path)
	if err != nil {
		t.Fatal(err)
	}

	if err := os.WriteFile(path, sealed[:len(sealed)-30], 0o600); err != nil {
		t.Fatal(err)
	}

	result, err := backup.New(cfg).Apply(context.Background(), newOp(protocol.OperationObserve, newTestUUID(), newTestUUID(),
		map[string]any{"artifact_path": path, "encryption_key": key, "upload_url": sink.url(), "upload_token": newTestEncryptionKey()}))
	if err != nil {
		t.Fatal(err)
	}

	if result.Status != protocol.StatusFailed {
		t.Fatalf("expected a failed download, got %s", result.Status)
	}

	if sink.final {
		t.Fatal("a cut-short backup must never be marked complete")
	}
}

func TestTheDownloadRefusesAnAddressThatIsNotAOneTimeUpload(t *testing.T) {
	sink := newUploadSink(t)
	cfg := backup.Config{ArtifactsRoot: t.TempDir(), StateRoots: map[string]string{"web.nginx.v1": t.TempDir()}, UploadClient: sink.server.Client()}

	path, key := createMailArtifact(t, cfg)

	for name, address := range map[string]string{"another path": sink.server.URL + "/somewhere/else", "plain http": "http://example.com/agent/v1/backup-uploads"} {
		result, err := backup.New(cfg).Apply(context.Background(), newOp(protocol.OperationObserve, newTestUUID(), newTestUUID(),
			map[string]any{"artifact_path": path, "encryption_key": key, "upload_url": address, "upload_token": newTestEncryptionKey()}))
		requireStatus(t, name, result, err, protocol.StatusRejected)
		requireErrorCode(t, name, result, "invalid_upload_url")
	}
}
