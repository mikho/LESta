package apache_test

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"net"
	"net/http"
	"net/http/fcgi"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/apache"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// startFakePhpFpm serves FastCGI on a unix socket the way a domain's own
// php-fpm pool would, answering every request with the SCRIPT_FILENAME apache
// asked it to run, so a test can prove which file apache handed over.
func startFakePhpFpm(t *testing.T) string {
	t.Helper()

	// Its own short temp dir: unix socket paths are limited to 108 bytes.
	dir, err := os.MkdirTemp("", "fcgi")
	if err != nil {
		t.Fatalf("creating socket dir: %v", err)
	}
	t.Cleanup(func() { _ = os.RemoveAll(dir) })

	socketPath := filepath.Join(dir, "php.sock")

	listener, err := net.Listen("unix", socketPath)
	if err != nil {
		t.Fatalf("listening on %s: %v", socketPath, err)
	}
	t.Cleanup(func() { _ = listener.Close() })

	go func() {
		_ = fcgi.Serve(listener, http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
			fmt.Fprintf(w, "FAKE-PHP script=%s", fcgi.ProcessEnv(r)["SCRIPT_FILENAME"])
		}))
	}()

	return socketPath
}

func phpPayload(domain, socket string) map[string]any {
	payload := apachePayload(domain, "127.0.0.1", false)
	payload["account_username"] = "lesta-t1"
	payload["php_socket"] = socket

	return payload
}

func getPath(t *testing.T, port int, domain, path string) (int, string) {
	t.Helper()

	req, err := http.NewRequest(http.MethodGet, fmt.Sprintf("http://127.0.0.1:%d%s", port, path), nil)
	if err != nil {
		t.Fatalf("building request: %v", err)
	}
	req.Host = domain

	resp, err := (&http.Client{Timeout: 5 * time.Second}).Do(req)
	if err != nil {
		t.Fatalf("GET %s: %v", path, err)
	}
	defer resp.Body.Close()

	body, err := io.ReadAll(resp.Body)
	if err != nil {
		t.Fatalf("reading %s: %v", path, err)
	}

	return resp.StatusCode, string(body)
}

// TestPhpDomainServesItsOwnDocrootAndHandsPhpToItsOwnSocket proves, against a
// real apache2, that a domain with a php_socket serves its real docroot
// (not this capability's marker page) and hands .php files to that socket.
func TestPhpDomainServesItsOwnDocrootAndHandsPhpToItsOwnSocket(t *testing.T) {
	d := newDisposableApache(t)
	socket := startFakePhpFpm(t)

	cfg := d.Config
	cfg.AccountsRoot = t.TempDir()

	var ensured []string
	cfg.EnsureDocroot = func(username, resourceID string) error {
		ensured = append(ensured, username+"/"+resourceID)

		return os.MkdirAll(filepath.Join(cfg.AccountsRoot, username, "domains", resourceID, "public"), 0o755)
	}

	capability := apache.New(cfg)
	resourceID := newTestUUID()
	domain := "php-site.contract.test"

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, phpPayload(domain, socket)))
	requireApplied(t, "create", created, err)

	if len(ensured) != 1 || ensured[0] != "lesta-t1/"+resourceID {
		t.Fatalf("expected EnsureDocroot to be called once for lesta-t1/%s, got %v", resourceID, ensured)
	}

	docroot := filepath.Join(cfg.AccountsRoot, "lesta-t1", "domains", resourceID, "public")
	if err := os.WriteFile(filepath.Join(docroot, "hello.html"), []byte("tenant static page"), 0o644); err != nil {
		t.Fatalf("writing static file: %v", err)
	}

	if status, body := getPath(t, d.Port, domain, "/hello.html"); status != http.StatusOK || body != "tenant static page" {
		t.Fatalf("static file: got %d %q, want 200 %q", status, body, "tenant static page")
	}

	status, body := getPath(t, d.Port, domain, "/app.php")
	if status != http.StatusOK || !strings.Contains(body, "FAKE-PHP") || !strings.Contains(body, filepath.Join(docroot, "app.php")) {
		t.Fatalf("php file: got %d %q, want 200 from the domain's own socket for %s", status, body, filepath.Join(docroot, "app.php"))
	}

	if _, body := getPath(t, d.Port, domain, "/"); strings.Contains(body, "LESTA-MARKER") {
		t.Fatalf("expected the domain's real docroot, not the marker page, got %q", body)
	}
}

// TestPhpDomainStillServesTheMaintenancePageWhenSuspended proves suspension
// still wins over a php_socket.
func TestPhpDomainStillServesTheMaintenancePageWhenSuspended(t *testing.T) {
	d := newDisposableApache(t)
	socket := startFakePhpFpm(t)

	cfg := d.Config
	cfg.AccountsRoot = t.TempDir()
	cfg.EnsureDocroot = func(username, resourceID string) error {
		return os.MkdirAll(filepath.Join(cfg.AccountsRoot, username, "domains", resourceID, "public"), 0o755)
	}

	capability := apache.New(cfg)
	resourceID := newTestUUID()
	domain := "php-suspended.contract.test"

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, phpPayload(domain, socket)))
	requireApplied(t, "create", created, err)

	suspendedPayload := phpPayload(domain, socket)
	suspendedPayload["suspended"] = true

	suspended, err := capability.Apply(context.Background(), newOp(protocol.OperationSuspend, resourceID, newTestUUID(), 2, suspendedPayload))
	requireApplied(t, "suspend", suspended, err)

	if _, body := getPath(t, d.Port, domain, "/"); !strings.Contains(body, "LESTA-SUSPENDED-MARKER") {
		t.Fatalf("expected the maintenance page while suspended, got %q", body)
	}

	if _, body := getPath(t, d.Port, domain, "/app.php"); strings.Contains(body, "FAKE-PHP") {
		t.Fatalf("expected no PHP to run while suspended, got %q", body)
	}
}

func TestPhpSocketRequiresAnAbsolutePathAndAValidAccountUsername(t *testing.T) {
	for name, mutate := range map[string]func(map[string]any){
		"relative socket":  func(p map[string]any) { p["php_socket"] = "run/php.sock" },
		"missing username": func(p map[string]any) { p["account_username"] = "" },
		"path in username": func(p map[string]any) { p["account_username"] = "../etc" },
	} {
		t.Run(name, func(t *testing.T) {
			payload := phpPayload("bad.contract.test", "/run/lesta-php/8.3/x.sock")
			mutate(payload)

			raw := mustJSON(t, payload)
			if _, err := apache.ParsePayload(raw); err == nil || !strings.Contains(err.Error(), "php_socket") {
				t.Fatalf("expected an invalid_php_socket rejection, got %v", err)
			}
		})
	}
}

func mustJSON(t *testing.T, v any) []byte {
	t.Helper()

	raw, err := json.Marshal(v)
	if err != nil {
		t.Fatalf("marshalling payload: %v", err)
	}

	return raw
}
