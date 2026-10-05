package nginx_test

import (
	"context"
	"crypto/tls"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/nginx"
	"github.com/mikho/LESta/agent/internal/protocol"
)

func TestParsePayloadRejectsRelativeWebmailSocket(t *testing.T) {
	payload := nginxPayload("mail.example.test", "127.0.0.1", false)
	payload["webmail_socket"] = "run/lesta-webmail/webmail.sock"

	raw, err := json.Marshal(payload)
	if err != nil {
		t.Fatalf("marshaling payload: %v", err)
	}

	_, err = nginx.ParsePayload(raw)

	var verr *nginx.ValidationError
	if !errors.As(err, &verr) || verr.Code != "invalid_webmail_socket" {
		t.Fatalf("expected invalid_webmail_socket, got %v", err)
	}
}

// webmailPayload is a create payload for the node's own mail hostname with
// webmail_socket set. The socket is never dialed by these tests: every
// request they make is answered by nginx itself (a redirect, or a static
// ACME challenge file), never by PHP-FPM.
func webmailPayload(domain string, certPath, keyPath string, suspended bool) map[string]any {
	p := nginxPayload(domain, "127.0.0.1", suspended)
	p["webmail_socket"] = "/run/lesta-webmail/webmail.sock"
	if certPath != "" {
		p["ssl"] = map[string]any{"mode": "manual", "certificate_path": certPath, "private_key_path": keyPath}
	}

	return p
}

func noRedirectClient(tlsConfig *tls.Config) *http.Client {
	return &http.Client{
		Timeout:       5 * time.Second,
		Transport:     &http.Transport{TLSClientConfig: tlsConfig},
		CheckRedirect: func(*http.Request, []*http.Request) error { return http.ErrUseLastResponse },
	}
}

func doWithRetry(t *testing.T, client *http.Client, req *http.Request) *http.Response {
	t.Helper()

	var lastErr error
	for deadline := time.Now().Add(5 * time.Second); time.Now().Before(deadline); {
		resp, err := client.Do(req)
		if err == nil {
			return resp
		}
		lastErr = err
		time.Sleep(50 * time.Millisecond)
	}
	t.Fatalf("never got a response from %s: %v", req.URL, lastErr)

	return nil
}

// TestWebmailTemplateRedirectsHttpAndServesAcmeOverHttps proves both server
// blocks of webmail.conf.tmpl against a real nginx: plain HTTP 301s to HTTPS
// (webmail carries login passwords), and the HTTPS block still serves an
// ACME challenge file. The latter is the regression guard for the
// `location ^~ /.well-known/acme-challenge/` choice: without ^~, the
// template's own `location ~ /\.` deny (a regex location, which nginx checks
// after the longest plain prefix) would answer it with 403.
func TestWebmailTemplateRedirectsHttpAndServesAcmeOverHttps(t *testing.T) {
	requireRealNginx(t)

	d := newDisposableNginx(t)
	capability := nginx.New(d.Config)
	domain := "mail.contract.test"

	certPath, keyPath, pool := selfSignedTestCertificate(t, t.TempDir(), domain)

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, webmailPayload(domain, certPath, keyPath, false)))
	if err != nil || created.Status != protocol.StatusApplied {
		t.Fatalf("create: status=%s err=%v errors=%+v", created.Status, err, created.Errors)
	}

	req, _ := http.NewRequest(http.MethodGet, fmt.Sprintf("http://127.0.0.1:%d/?_task=mail", d.Port), nil)
	req.Host = domain
	resp := doWithRetry(t, noRedirectClient(nil), req)
	_ = resp.Body.Close()

	if resp.StatusCode != http.StatusMovedPermanently || !strings.HasPrefix(resp.Header.Get("Location"), "https://"+domain+"/") {
		t.Fatalf("expected a 301 to https://%s/, got %d Location=%q", domain, resp.StatusCode, resp.Header.Get("Location"))
	}

	token := "webmail-acme-token"
	if err := os.WriteFile(filepath.Join(d.Config.AcmeChallengeDir, token), []byte("key-auth"), 0o644); err != nil {
		t.Fatalf("writing challenge file: %v", err)
	}

	req, _ = http.NewRequest(http.MethodGet, fmt.Sprintf("https://127.0.0.1:%d/.well-known/acme-challenge/%s", d.Config.SSLPort, token), nil)
	req.Host = domain
	resp = doWithRetry(t, noRedirectClient(&tls.Config{RootCAs: pool, ServerName: domain}), req)
	body, _ := io.ReadAll(resp.Body)
	_ = resp.Body.Close()

	if resp.StatusCode != http.StatusOK || string(body) != "key-auth" {
		t.Fatalf("expected the HTTPS block to serve the ACME challenge file, got %d: %q", resp.StatusCode, body)
	}
}

// TestWebmailTemplateNeedsACertificate proves webmail_socket alone never
// selects webmail.conf.tmpl: without a certificate the domain keeps its own
// ordinary template (it would otherwise force-redirect to an HTTPS listener
// that doesn't exist).
func TestWebmailTemplateNeedsACertificate(t *testing.T) {
	requireRealNginx(t)

	d := newDisposableNginx(t)
	capability := nginx.New(d.Config)
	resourceID := newTestUUID()
	domain := "mail-nocert.contract.test"

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, webmailPayload(domain, "", "", false)))
	if err != nil || created.Status != protocol.StatusApplied {
		t.Fatalf("create: status=%s err=%v errors=%+v", created.Status, err, created.Errors)
	}

	if body := getVhost(t, d.Port, domain); !strings.Contains(body, resourceID) {
		t.Fatalf("expected the ordinary marker template without a certificate, got %q", body)
	}
}

// TestWebmailTemplateYieldsToSuspended proves suspended still wins over
// webmail, the same priority every other template already has.
func TestWebmailTemplateYieldsToSuspended(t *testing.T) {
	requireRealNginx(t)

	d := newDisposableNginx(t)
	capability := nginx.New(d.Config)
	domain := "mail-suspended.contract.test"

	certPath, keyPath, _ := selfSignedTestCertificate(t, t.TempDir(), domain)

	created, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, webmailPayload(domain, certPath, keyPath, true)))
	if err != nil || created.Status != protocol.StatusApplied {
		t.Fatalf("create: status=%s err=%v errors=%+v", created.Status, err, created.Errors)
	}

	if body := getVhost(t, d.Port, domain); !strings.Contains(body, "LESTA-SUSPENDED-MARKER") {
		t.Fatalf("expected the suspended page, got %q", body)
	}
}
