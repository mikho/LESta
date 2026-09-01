package nginx_test

import (
	"context"
	"crypto/ecdsa"
	"crypto/elliptic"
	"crypto/rand"
	"crypto/tls"
	"crypto/x509"
	"crypto/x509/pkix"
	"encoding/json"
	"encoding/pem"
	"errors"
	"fmt"
	"io"
	"math/big"
	"net/http"
	"net/http/httptest"
	"net/url"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/nginx"
	"github.com/mikho/LESta/agent/internal/contract"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// TestNginxCapabilitySatisfiesContract runs the exact same shared contract
// suite internal/capability/fake's stub must also pass, against a real
// NginxCapability backed by a fresh, fully disposable nginx process per case.
func TestNginxCapabilitySatisfiesContract(t *testing.T) {
	requireRealNginx(t)

	contract.RunAgainst(t, func(t *testing.T) protocol.Capability {
		d := newDisposableNginx(t)

		return nginx.New(d.Config)
	})
}

// TestCreateSuspendUnsuspendServesRealHTTPContent independently re-verifies,
// with its own plain HTTP requests (not trusting NginxCapability's own
// internal health check), that create/suspend/unsuspend really do change what
// a real nginx process serves on a real port.
func TestCreateSuspendUnsuspendServesRealHTTPContent(t *testing.T) {
	requireRealNginx(t)

	d := newDisposableNginx(t)
	capability := nginx.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "real-http.contract.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, nginxPayload(domain, "127.0.0.1", false)))
	if err != nil || created.Status != protocol.StatusApplied {
		t.Fatalf("create: status=%s err=%v errors=%+v", created.Status, err, created.Errors)
	}

	body := getVhost(t, d.Port, domain)
	if !strings.Contains(body, "LESTA-MARKER") || !strings.Contains(body, resourceID) {
		t.Fatalf("expected the default vhost's own real HTTP response to contain its LESTA-MARKER and resource id, got: %q", body)
	}
	t.Logf("confirmed real HTTP 200 from the created vhost, body: %q", strings.TrimSpace(body))

	suspended, err := capability.Apply(ctx, newOp(protocol.OperationSuspend, resourceID, newTestUUID(), 2, nginxPayload(domain, "127.0.0.1", true)))
	if err != nil || suspended.Status != protocol.StatusApplied {
		t.Fatalf("suspend: status=%s err=%v errors=%+v", suspended.Status, err, suspended.Errors)
	}

	body = getVhost(t, d.Port, domain)
	if !strings.Contains(body, "LESTA-SUSPENDED-MARKER") {
		t.Fatalf("expected the suspended vhost's real HTTP response to contain the maintenance page marker, got: %q", body)
	}
	if strings.Contains(body, "LESTA-MARKER resource=") {
		t.Fatalf("expected the suspended response to NOT contain the default-template marker, got: %q", body)
	}
	t.Logf("confirmed real HTTP 200 with the maintenance page after suspend, body: %q", strings.TrimSpace(body))

	unsuspended, err := capability.Apply(ctx, newOp(protocol.OperationUnsuspend, resourceID, newTestUUID(), 3, nginxPayload(domain, "127.0.0.1", false)))
	if err != nil || unsuspended.Status != protocol.StatusApplied {
		t.Fatalf("unsuspend: status=%s err=%v errors=%+v", unsuspended.Status, err, unsuspended.Errors)
	}

	body = getVhost(t, d.Port, domain)
	if !strings.Contains(body, "LESTA-MARKER") || !strings.Contains(body, resourceID) {
		t.Fatalf("expected unsuspend to restore the original vhost's real HTTP response, got: %q", body)
	}
	t.Logf("confirmed real HTTP 200 with the original page restored after unsuspend, body: %q", strings.TrimSpace(body))

	if unsuspended.ObservedStateDigest != created.ObservedStateDigest {
		t.Fatalf("expected unsuspend's digest to match create's: create=%s unsuspend=%s", created.ObservedStateDigest, unsuspended.ObservedStateDigest)
	}
}

// TestDeleteRemovesLiveFragmentAndNginxStaysHealthy independently confirms,
// via a real filesystem check and a real HTTP request, that delete actually
// removes the live fragment and that nginx is still serving other vhosts
// afterwards.
func TestDeleteRemovesLiveFragmentAndNginxStaysHealthy(t *testing.T) {
	requireRealNginx(t)

	d := newDisposableNginx(t)
	capability := nginx.New(d.Config)
	ctx := context.Background()

	survivorID := newTestUUID()
	survivorDomain := "survivor.contract.test"
	survivorResult, err := capability.Apply(ctx, newOp(protocol.OperationCreate, survivorID, newTestUUID(), 1, nginxPayload(survivorDomain, "127.0.0.1", false)))
	if err != nil || survivorResult.Status != protocol.StatusApplied {
		t.Fatalf("creating survivor: status=%s err=%v", survivorResult.Status, err)
	}

	victimID := newTestUUID()
	victimDomain := "victim.contract.test"
	victimResult, err := capability.Apply(ctx, newOp(protocol.OperationCreate, victimID, newTestUUID(), 1, nginxPayload(victimDomain, "127.0.0.1", false)))
	if err != nil || victimResult.Status != protocol.StatusApplied {
		t.Fatalf("creating victim: status=%s err=%v", victimResult.Status, err)
	}

	liveFragment := filepath.Join(d.Config.LiveDir, victimID+".conf")
	if _, err := os.Stat(liveFragment); err != nil {
		t.Fatalf("expected the victim's live fragment to exist before delete: %v", err)
	}

	deleted, err := capability.Apply(ctx, newOp(protocol.OperationDelete, victimID, newTestUUID(), 2, nginxPayload(victimDomain, "127.0.0.1", false)))
	if err != nil || deleted.Status != protocol.StatusApplied {
		t.Fatalf("delete: status=%s err=%v errors=%+v", deleted.Status, err, deleted.Errors)
	}

	if _, err := os.Stat(liveFragment); !os.IsNotExist(err) {
		t.Fatalf("expected the victim's live fragment to be gone after delete, stat err=%v", err)
	}
	t.Logf("confirmed the live fragment %s no longer exists on disk after delete", liveFragment)

	// nginx itself must still be healthy, serving the surviving vhost, a real
	// HTTP request away.
	body := getVhost(t, d.Port, survivorDomain)
	if !strings.Contains(body, survivorID) {
		t.Fatalf("expected the surviving vhost to still serve its own marker after the victim's delete, got: %q", body)
	}
	t.Logf("confirmed nginx is still healthy and serving the surviving vhost after delete, body: %q", strings.TrimSpace(body))
}

// TestCreateFailureIsFailedNeverDegraded forces a real reload failure (via
// Config.ReloadCommand, the seam this phase's Config exposes for exactly this
// kind of substitution) for a brand-new resource. Since create has no prior
// generation to fall back to, the ADR's own table says this must be `failed`,
// never `degraded`, with no rollback attempted.
func TestCreateFailureIsFailedNeverDegraded(t *testing.T) {
	requireRealNginx(t)

	d := newDisposableNginx(t)
	cfg := d.Config
	cfg.ReloadCommand = []string{"false"} // always fails, deterministically, no real reload ever happens

	capability := nginx.New(cfg)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "create-failure.contract.test"

	result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, nginxPayload(domain, "127.0.0.1", false)))
	if err != nil {
		t.Fatalf("Apply returned an error (no verdict reached): %v", err)
	}
	if result.Status != protocol.StatusFailed {
		t.Fatalf("expected create's reload failure to be reported as failed, got %s (errors=%+v)", result.Status, result.Errors)
	}
	t.Logf("confirmed a real reload failure on create reports failed (never degraded): status=%s errors=%+v", result.Status, result.Errors)
}

// TestUpdateFailureWithFailingRollbackReportsFailed forces every reload call
// to fail (via Config.ReloadCommand), including the rollback's own. The ADR's
// table says failed is reported only when even the rollback's own health
// check (which here never gets the chance to run: rollback's reload itself
// fails first) also fails.
func TestUpdateFailureWithFailingRollbackReportsFailed(t *testing.T) {
	requireRealNginx(t)

	d := newDisposableNginx(t)
	capability := nginx.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "update-double-failure.contract.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, nginxPayload(domain, "127.0.0.1", false)))
	if err != nil || created.Status != protocol.StatusApplied {
		t.Fatalf("create: status=%s err=%v errors=%+v", created.Status, err, created.Errors)
	}

	// Now break reload entirely, for the update AND for its own rollback
	// attempt.
	cfg := d.Config
	cfg.ReloadCommand = []string{"false"}
	brokenCapability := nginx.New(cfg)

	updated, err := brokenCapability.Apply(ctx, newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 2, nginxPayload(domain, "127.0.0.1", false)))
	if err != nil {
		t.Fatalf("Apply returned an error (no verdict reached): %v", err)
	}
	if updated.Status != protocol.StatusFailed {
		t.Fatalf("expected a doubly-failing rollback to report failed, got %s (errors=%+v)", updated.Status, updated.Errors)
	}
	t.Logf("confirmed a real reload failure whose rollback also fails to reload reports failed: status=%s errors=%+v", updated.Status, updated.Errors)
}

// TestUpdateFailureWithSuccessfulRollbackReportsDegraded uses a tiny counting
// shell wrapper as Config.ReloadCommand: it fails exactly once (simulating a
// transient real reload failure for the update), then delegates to the real
// `nginx -s reload` for every subsequent call, including the rollback's own.
// Real nginx still does the actual validating, activating, reloading, and
// serving throughout; only the *signal that would fail* is deterministically
// scripted, since a genuinely differential real nginx failure (reload fails
// for the new content specifically, but would succeed for the restored
// content) turned out not to be constructible portably from payload content
// alone (nginx's own `-t` reliably predicts real reload failures for content
// reasons, so the only real failures reachable this way are environmental —
// e.g. no master process to signal — which are not selectively recoverable).
func TestUpdateFailureWithSuccessfulRollbackReportsDegraded(t *testing.T) {
	requireRealNginx(t)

	d := newDisposableNginx(t)
	capability := nginx.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "update-degraded.contract.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, nginxPayload(domain, "127.0.0.1", false)))
	if err != nil || created.Status != protocol.StatusApplied {
		t.Fatalf("create: status=%s err=%v errors=%+v", created.Status, err, created.Errors)
	}

	body := getVhost(t, d.Port, domain)
	if !strings.Contains(body, resourceID) {
		t.Fatalf("expected the created vhost to serve its own marker before the forced failure, got: %q", body)
	}

	script := writeFailOnceReloadScript(t)
	cfg := d.Config
	cfg.ReloadCommand = []string{
		"sh", script, filepath.Join(t.TempDir(), "counter"), "1",
		"-p", d.Prefix, "-c", d.Config.NginxConfPath, "-s", "reload",
	}
	flakyCapability := nginx.New(cfg)

	updated, err := flakyCapability.Apply(ctx, newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 2, nginxPayload("changed-"+domain, "127.0.0.1", false)))
	if err != nil {
		t.Fatalf("Apply returned an error (no verdict reached): %v", err)
	}
	if updated.Status != protocol.StatusDegraded {
		t.Fatalf("expected a recoverable reload failure to report degraded, got %s (errors=%+v)", updated.Status, updated.Errors)
	}
	if updated.ObservedStateDigest != created.ObservedStateDigest {
		t.Fatalf("expected the degraded result's digest to match the restored (original) generation: created=%s degraded=%s", created.ObservedStateDigest, updated.ObservedStateDigest)
	}
	t.Logf("confirmed a real, recoverable reload failure reports degraded: status=%s errors=%+v", updated.Status, updated.Errors)

	// Independently re-confirm, with our own real HTTP request, that the
	// ORIGINAL domain is still being served (last-known-good), not the
	// attempted (and rolled back) new one.
	body = getVhost(t, d.Port, domain)
	if !strings.Contains(body, resourceID) {
		t.Fatalf("expected the original vhost to still be served for real after a degraded rollback, got: %q", body)
	}
	t.Logf("confirmed via a real HTTP request that the original vhost is still being served after the degraded rollback, body: %q", strings.TrimSpace(body))
}

// TestParsePayloadAcceptsApacheProxyWebTemplate is a pure unit test (no real
// nginx needed) proving ParsePayload's own allow-list accepts "apache-proxy"
// alongside "default", per payload.go's supportedWebTemplates map.
func TestParsePayloadAcceptsApacheProxyWebTemplate(t *testing.T) {
	raw, err := json.Marshal(nginxPayloadWithTemplate("proxy.example.test", "127.0.0.1", "apache-proxy", false))
	if err != nil {
		t.Fatalf("marshaling payload: %v", err)
	}

	payload, err := nginx.ParsePayload(raw)
	if err != nil {
		t.Fatalf("expected apache-proxy to be accepted, got error: %v", err)
	}
	if payload.WebTemplate != "apache-proxy" {
		t.Fatalf("expected WebTemplate to round-trip as apache-proxy, got %q", payload.WebTemplate)
	}
}

// TestParsePayloadRejectsUnknownWebTemplate proves the allow-list still
// rejects anything outside {"default", "apache-proxy"}, with the same
// unsupported_web_template error code the shared contract suite's own
// caseUnsupportedWebTemplateIsRejected exercises.
func TestParsePayloadRejectsUnknownWebTemplate(t *testing.T) {
	raw, err := json.Marshal(nginxPayloadWithTemplate("proxy.example.test", "127.0.0.1", "apache-classic", false))
	if err != nil {
		t.Fatalf("marshaling payload: %v", err)
	}

	_, err = nginx.ParsePayload(raw)

	var verr *nginx.ValidationError
	if !errors.As(err, &verr) {
		t.Fatalf("expected a *nginx.ValidationError, got %v", err)
	}
	if verr.Code != "unsupported_web_template" {
		t.Fatalf("expected code unsupported_web_template, got %q", verr.Code)
	}
}

// TestApacheProxyTemplateProxiesToBackend proves apache_proxy.conf.tmpl
// actually works as a reverse proxy against a real backend: a plain
// httptest.Server stands in for Apache here (this file's own suite has no
// need for a real disposable Apache just to prove nginx's own template
// renders a working proxy_pass; the cross-capability proof against a real
// disposable Apache lives in a separate top-level test).
func TestApacheProxyTemplateProxiesToBackend(t *testing.T) {
	requireRealNginx(t)

	backend := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		fmt.Fprintf(w, "BACKEND-MARKER host=%s", r.Host)
	}))
	defer backend.Close()

	backendURL, err := url.Parse(backend.URL)
	if err != nil {
		t.Fatalf("parsing backend URL: %v", err)
	}

	d := newDisposableNginx(t)
	cfg := d.Config
	cfg.ProxyBackend = backendURL.Host
	capability := nginx.New(cfg)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "apache-proxy.contract.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, nginxPayloadWithTemplate(domain, "127.0.0.1", "apache-proxy", false)))
	if err != nil || created.Status != protocol.StatusApplied {
		t.Fatalf("create: status=%s err=%v errors=%+v", created.Status, err, created.Errors)
	}

	body := getVhost(t, d.Port, domain)
	if !strings.Contains(body, "BACKEND-MARKER") {
		t.Fatalf("expected the apache-proxy vhost to proxy through to the backend, got: %q", body)
	}
	t.Logf("confirmed the apache-proxy template really proxies a real HTTP request to its configured backend: %q", body)
}

// TestApacheProxyTemplateYieldsToSuspendedPage proves suspended always wins
// over WebTemplate: a suspended apache-proxy resource must serve nginx's
// ordinary suspended page directly, never attempting to reach Apache at all.
// ProxyBackend deliberately points at nothing reachable to prove it is never
// even contacted.
func TestApacheProxyTemplateYieldsToSuspendedPage(t *testing.T) {
	requireRealNginx(t)

	d := newDisposableNginx(t)
	cfg := d.Config
	cfg.ProxyBackend = "127.0.0.1:1" // nothing listens here; must never be hit
	capability := nginx.New(cfg)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "apache-proxy-suspended.contract.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, nginxPayloadWithTemplate(domain, "127.0.0.1", "apache-proxy", true)))
	if err != nil || created.Status != protocol.StatusApplied {
		t.Fatalf("create: status=%s err=%v errors=%+v", created.Status, err, created.Errors)
	}

	body := getVhost(t, d.Port, domain)
	if !strings.Contains(body, "LESTA-SUSPENDED-MARKER") {
		t.Fatalf("expected a suspended apache-proxy resource to serve the ordinary suspended page, got: %q", body)
	}
	t.Logf("confirmed a suspended apache-proxy resource serves the suspended page directly, never reaching the (unreachable) backend: %q", strings.TrimSpace(body))
}

// TestApacheProxyCreateSucceedsEvenWithUnreachableBackend proves the
// deliberate, documented health-check limitation: nginx's own apache-proxy
// health check (waitHealthyGeneric) only proves nginx itself accepted and is
// running the new proxy config, never that the configured backend is
// actually reachable. A marker-checked health check (waitHealthy) would
// instead make this create fail against an unreachable backend, which is
// exactly the flakiness capability.go's own comment says this is avoiding.
func TestApacheProxyCreateSucceedsEvenWithUnreachableBackend(t *testing.T) {
	requireRealNginx(t)

	d := newDisposableNginx(t)
	cfg := d.Config
	cfg.ProxyBackend = "127.0.0.1:1" // nothing listens here
	capability := nginx.New(cfg)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "apache-proxy-no-backend.contract.test"

	result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, nginxPayloadWithTemplate(domain, "127.0.0.1", "apache-proxy", false)))
	if err != nil {
		t.Fatalf("Apply returned an error (no verdict reached): %v", err)
	}
	if result.Status != protocol.StatusApplied {
		t.Fatalf("expected create to succeed via the generic (nginx-only) health check even with an unreachable backend, got %s (errors=%+v)", result.Status, result.Errors)
	}
	t.Logf("confirmed nginx's own apache-proxy health check only proves nginx itself is healthy, not the backend: status=%s", result.Status)

	req, err := http.NewRequest(http.MethodGet, fmt.Sprintf("http://127.0.0.1:%d/", d.Port), nil)
	if err != nil {
		t.Fatalf("building request: %v", err)
	}
	req.Host = domain

	resp, err := (&http.Client{Timeout: 5 * time.Second}).Do(req)
	if err != nil {
		t.Fatalf("expected nginx itself to accept the connection even though its backend is unreachable: %v", err)
	}
	defer func() { _ = resp.Body.Close() }()

	if resp.StatusCode < http.StatusInternalServerError {
		t.Fatalf("expected a 5xx from nginx's own proxy failure against an unreachable backend, got %d", resp.StatusCode)
	}
}

// TestApacheProxyRollbackUsesGenericHealthCheck exercises the matching
// branch inside recoverFromFailure: a forced, recoverable reload failure on
// an apache-proxy resource must roll back and report degraded using the
// generic health check too, never blocking on the (here, deliberately
// unreachable) backend during rollback.
func TestApacheProxyRollbackUsesGenericHealthCheck(t *testing.T) {
	requireRealNginx(t)

	d := newDisposableNginx(t)
	cfg := d.Config
	cfg.ProxyBackend = "127.0.0.1:1" // unreachable throughout
	capability := nginx.New(cfg)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "apache-proxy-rollback.contract.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, nginxPayloadWithTemplate(domain, "127.0.0.1", "apache-proxy", false)))
	if err != nil || created.Status != protocol.StatusApplied {
		t.Fatalf("create: status=%s err=%v errors=%+v", created.Status, err, created.Errors)
	}

	script := writeFailOnceReloadScript(t)
	flakyCfg := cfg
	flakyCfg.ReloadCommand = []string{
		"sh", script, filepath.Join(t.TempDir(), "counter"), "1",
		"-p", d.Prefix, "-c", d.Config.NginxConfPath, "-s", "reload",
	}
	flakyCapability := nginx.New(flakyCfg)

	updated, err := flakyCapability.Apply(ctx, newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 2, nginxPayloadWithTemplate("changed-"+domain, "127.0.0.1", "apache-proxy", false)))
	if err != nil {
		t.Fatalf("Apply returned an error (no verdict reached): %v", err)
	}
	if updated.Status != protocol.StatusDegraded {
		t.Fatalf("expected a recoverable reload failure on an apache-proxy resource to report degraded, got %s (errors=%+v)", updated.Status, updated.Errors)
	}
	t.Logf("confirmed a rolled-back apache-proxy update reports degraded using the generic health check, never blocking on the unreachable backend: status=%s", updated.Status)
}

func writeFailOnceReloadScript(t *testing.T) string {
	t.Helper()

	path := filepath.Join(t.TempDir(), "flaky-reload.sh")
	script := `#!/bin/sh
COUNTER_FILE="$1"
FAIL_COUNT="$2"
shift 2
N=0
if [ -f "$COUNTER_FILE" ]; then
  N=$(cat "$COUNTER_FILE")
fi
N=$((N + 1))
echo "$N" > "$COUNTER_FILE"
if [ "$N" -le "$FAIL_COUNT" ]; then
  echo "simulated reload failure #$N (fail_count=$FAIL_COUNT)" >&2
  exit 1
fi
exec nginx "$@"
`
	if err := os.WriteFile(path, []byte(script), 0o755); err != nil {
		t.Fatalf("writing flaky reload script: %v", err)
	}

	return path
}

// getVhost issues a real HTTP GET to the disposable instance's port, with the
// Host header set to domain, and returns the response body. It fails the test
// if the request doesn't succeed with 200.
func getVhost(t *testing.T, port int, domain string) string {
	t.Helper()

	url := fmt.Sprintf("http://127.0.0.1:%d/", port)

	req, err := http.NewRequest(http.MethodGet, url, nil)
	if err != nil {
		t.Fatalf("building request: %v", err)
	}
	req.Host = domain

	client := &http.Client{Timeout: 5 * time.Second}

	var lastErr error

	for deadline := time.Now().Add(5 * time.Second); time.Now().Before(deadline); {
		resp, err := client.Do(req)
		if err != nil {
			lastErr = err
			time.Sleep(50 * time.Millisecond)

			continue
		}

		body, err := io.ReadAll(resp.Body)
		_ = resp.Body.Close()

		if err != nil {
			t.Fatalf("reading response body: %v", err)
		}

		if resp.StatusCode != http.StatusOK {
			t.Fatalf("expected 200 from %s (Host: %s), got %d: %s", url, domain, resp.StatusCode, body)
		}

		return string(body)
	}

	t.Fatalf("never got a response from %s (Host: %s): %v", url, domain, lastErr)

	return ""
}

func newOp(operation protocol.Operation, resourceID, idempotencyKey string, desiredStateVersion int, payload map[string]any) protocol.OperationEnvelope {
	raw, err := json.Marshal(payload)
	if err != nil {
		panic(fmt.Sprintf("marshaling test payload: %v", err))
	}

	now := time.Now().UTC()

	return protocol.OperationEnvelope{
		ProtocolVersion:     "1",
		Capability:          "web.nginx.v1",
		Operation:           operation,
		ResourceID:          resourceID,
		DesiredStateVersion: desiredStateVersion,
		IdempotencyKey:      idempotencyKey,
		CorrelationID:       newTestUUID(),
		Deadline:            now.Add(10 * time.Second),
		IssuedAt:            now,
		RequestDigest:       "sha256:" + strings.Repeat("0", 64),
		Payload:             raw,
	}
}

func nginxPayload(domain, ip string, suspended bool) map[string]any {
	return nginxPayloadWithTemplate(domain, ip, "default", suspended)
}

// nginxPayloadWithTemplate is nginxPayload's own more general form, letting
// callers exercise a web_template other than "default" (e.g. "apache-proxy",
// or a deliberately unsupported value for a rejection test).
func nginxPayloadWithTemplate(domain, ip, webTemplate string, suspended bool) map[string]any {
	return map[string]any{
		"domain":       domain,
		"aliases":      []string{},
		"ip_address":   ip,
		"web_template": webTemplate,
		"ssl":          map[string]any{"mode": "off"},
		"suspended":    suspended,
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

// TestAcmeChallengeLocationServesRealFile proves the new, always-present
// `.well-known/acme-challenge/` location block actually serves a file placed
// under Config.AcmeChallengeDir, via a real HTTP GET against a real
// disposable nginx instance -- the exact mechanism tls.acme.v1's own
// http01_challenge writes are meant to satisfy (see
// internal/capability/acme's own package doc comment).
func TestAcmeChallengeLocationServesRealFile(t *testing.T) {
	requireRealNginx(t)

	d := newDisposableNginx(t)
	capability := nginx.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "acme-challenge.contract.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, nginxPayload(domain, "127.0.0.1", false)))
	if err != nil || created.Status != protocol.StatusApplied {
		t.Fatalf("create: status=%s err=%v errors=%+v", created.Status, err, created.Errors)
	}

	token := "test-token-123"
	keyAuthorization := "test-token-123.thumbprint-placeholder"

	if err := os.WriteFile(filepath.Join(d.Config.AcmeChallengeDir, token), []byte(keyAuthorization), 0o644); err != nil {
		t.Fatalf("writing challenge file: %v", err)
	}

	url := fmt.Sprintf("http://127.0.0.1:%d/.well-known/acme-challenge/%s", d.Port, token)

	req, err := http.NewRequest(http.MethodGet, url, nil)
	if err != nil {
		t.Fatalf("building request: %v", err)
	}
	req.Host = domain

	client := &http.Client{Timeout: 5 * time.Second}

	var (
		body    []byte
		lastErr error
	)

	for deadline := time.Now().Add(5 * time.Second); time.Now().Before(deadline); {
		resp, err := client.Do(req)
		if err != nil {
			lastErr = err
			time.Sleep(50 * time.Millisecond)

			continue
		}

		body, err = io.ReadAll(resp.Body)
		_ = resp.Body.Close()
		if err != nil {
			t.Fatalf("reading response body: %v", err)
		}
		if resp.StatusCode != http.StatusOK {
			t.Fatalf("expected 200 from %s, got %d: %s", url, resp.StatusCode, body)
		}

		break
	}

	if len(body) == 0 && lastErr != nil {
		t.Fatalf("never got a response from %s: %v", url, lastErr)
	}
	if string(body) != keyAuthorization {
		t.Fatalf("expected the acme-challenge location to serve %q, got %q", keyAuthorization, string(body))
	}
	t.Logf("confirmed a real HTTP 200 from the .well-known/acme-challenge/ location block, body: %q", string(body))
}

// selfSignedTestCertificate generates, entirely on the fly via Go's own
// crypto/tls and crypto/x509 (never fetching or vendoring a real Let's
// Encrypt certificate), a minimal self-signed ECDSA certificate valid for
// domain, writing cert.pem/key.pem under dir and returning their paths plus
// an *x509.CertPool a test HTTPS client can use to trust it.
func selfSignedTestCertificate(t *testing.T, dir, domain string) (certPath, keyPath string, pool *x509.CertPool) {
	t.Helper()

	priv, err := ecdsa.GenerateKey(elliptic.P256(), rand.Reader)
	if err != nil {
		t.Fatalf("generating test certificate key: %v", err)
	}

	serial, err := rand.Int(rand.Reader, new(big.Int).Lsh(big.NewInt(1), 128))
	if err != nil {
		t.Fatalf("generating test certificate serial number: %v", err)
	}

	template := x509.Certificate{
		SerialNumber:          serial,
		Subject:               pkix.Name{CommonName: domain},
		DNSNames:              []string{domain},
		NotBefore:             time.Now().Add(-time.Hour),
		NotAfter:              time.Now().Add(time.Hour),
		KeyUsage:              x509.KeyUsageDigitalSignature | x509.KeyUsageCertSign,
		ExtKeyUsage:           []x509.ExtKeyUsage{x509.ExtKeyUsageServerAuth},
		BasicConstraintsValid: true,
		IsCA:                  true,
	}

	derBytes, err := x509.CreateCertificate(rand.Reader, &template, &template, &priv.PublicKey, priv)
	if err != nil {
		t.Fatalf("creating self-signed test certificate: %v", err)
	}

	certPath = filepath.Join(dir, "cert.pem")
	keyPath = filepath.Join(dir, "key.pem")

	certPEM := pem.EncodeToMemory(&pem.Block{Type: "CERTIFICATE", Bytes: derBytes})
	if err := os.WriteFile(certPath, certPEM, 0o644); err != nil {
		t.Fatalf("writing test certificate: %v", err)
	}

	keyBytes, err := x509.MarshalECPrivateKey(priv)
	if err != nil {
		t.Fatalf("marshaling test private key: %v", err)
	}

	keyPEM := pem.EncodeToMemory(&pem.Block{Type: "EC PRIVATE KEY", Bytes: keyBytes})
	if err := os.WriteFile(keyPath, keyPEM, 0o600); err != nil {
		t.Fatalf("writing test private key: %v", err)
	}

	pool = x509.NewCertPool()
	pool.AppendCertsFromPEM(certPEM)

	return certPath, keyPath, pool
}

// TestDefaultSslTemplateServesRealHTTPSContent proves default_ssl.conf.tmpl's
// selection path (CertificatePath != "") actually terminates real TLS: a
// real disposable nginx instance is configured with a real self-signed test
// certificate generated on the fly, then a real HTTPS GET against its own
// SSLPort must succeed and carry the vhost's own marker, while a plain HTTP
// GET against Port must still work unmodified (no forced redirect this
// phase, per the plan).
func TestDefaultSslTemplateServesRealHTTPSContent(t *testing.T) {
	requireRealNginx(t)

	d := newDisposableNginx(t)
	domain := "ssl-vhost.contract.test"

	certPath, keyPath, pool := selfSignedTestCertificate(t, t.TempDir(), domain)

	cfg := d.Config
	capability := nginx.New(cfg)
	ctx := context.Background()

	resourceID := newTestUUID()

	payload := nginxPayload(domain, "127.0.0.1", false)
	payload["ssl"] = map[string]any{
		"mode":             "lets_encrypt",
		"certificate_path": certPath,
		"private_key_path": keyPath,
	}

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, payload))
	if err != nil || created.Status != protocol.StatusApplied {
		t.Fatalf("create: status=%s err=%v errors=%+v", created.Status, err, created.Errors)
	}

	// Plain HTTP still works unmodified: no forced redirect this phase.
	body := getVhost(t, d.Port, domain)
	if !strings.Contains(body, resourceID) {
		t.Fatalf("expected plain HTTP to still serve the vhost's own marker, got: %q", body)
	}
	t.Logf("confirmed plain HTTP still works with no forced redirect, body: %q", strings.TrimSpace(body))

	httpsClient := &http.Client{
		Timeout: 5 * time.Second,
		Transport: &http.Transport{
			TLSClientConfig: &tls.Config{RootCAs: pool, ServerName: domain},
		},
	}

	httpsURL := fmt.Sprintf("https://127.0.0.1:%d/", cfg.SSLPort)

	req, err := http.NewRequest(http.MethodGet, httpsURL, nil)
	if err != nil {
		t.Fatalf("building HTTPS request: %v", err)
	}
	req.Host = domain

	var (
		httpsBody []byte
		lastErr   error
	)

	for deadline := time.Now().Add(5 * time.Second); time.Now().Before(deadline); {
		resp, err := httpsClient.Do(req)
		if err != nil {
			lastErr = err
			time.Sleep(50 * time.Millisecond)

			continue
		}

		httpsBody, err = io.ReadAll(resp.Body)
		_ = resp.Body.Close()
		if err != nil {
			t.Fatalf("reading HTTPS response body: %v", err)
		}
		if resp.StatusCode != http.StatusOK {
			t.Fatalf("expected 200 from %s, got %d: %s", httpsURL, resp.StatusCode, httpsBody)
		}

		break
	}

	if len(httpsBody) == 0 {
		t.Fatalf("never got a real HTTPS response from %s: %v", httpsURL, lastErr)
	}
	if !strings.Contains(string(httpsBody), resourceID) {
		t.Fatalf("expected the real HTTPS response to contain the vhost's own marker, got: %q", string(httpsBody))
	}
	t.Logf("confirmed a real HTTPS request, terminated with a real self-signed certificate, served the vhost's own marker: %q", strings.TrimSpace(string(httpsBody)))
}
