package apache_test

import (
	"context"
	"crypto/rand"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/apache"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// --- envelope/payload construction helpers -------------------------------
//
// This suite is deliberately self-contained: it duplicates the small set of
// helpers nginx_test.go and bind9_test.go each carry, rather than importing
// internal/contract. That package's own contract.Cases table asserts digest
// identity across create/suspend/unsuspend (see
// caseCreateSuspendUnsuspendRestoresDigest), which is correct for nginx (whose
// rendered vhost never embeds a generation number) but would be silently
// wrong for Apache: this capability's own vhost fragment points its
// DocumentRoot at its OWN generation's content directory (see capability.go's
// own top-of-file doc comment), so every create/suspend/unsuspend necessarily
// lands in a different generation number and therefore a different LiveDir
// digest, exactly the same reasoning bind9's own bind9_test.go documents for
// its own TestUnsuspendRestoresContent. Real HTTP content, not digest
// identity, is what this suite asserts instead wherever nginx's contract
// suite would have compared digests.

func newTestUUID() string {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		panic(fmt.Sprintf("generating test UUID: %v", err))
	}

	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80

	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16])
}

func newOp(operation protocol.Operation, resourceID, idempotencyKey string, desiredStateVersion int, payload map[string]any) protocol.OperationEnvelope {
	raw, err := json.Marshal(payload)
	if err != nil {
		panic(fmt.Sprintf("marshaling test payload: %v", err))
	}

	now := time.Now().UTC()

	return protocol.OperationEnvelope{
		ProtocolVersion:     "1",
		Capability:          "web.apache.v1",
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

func apachePayload(domain, ip string, suspended bool) map[string]any {
	return map[string]any{
		"domain":       domain,
		"aliases":      []string{},
		"ip_address":   ip,
		"web_template": "default",
		"ssl":          map[string]any{"mode": "off"},
		"suspended":    suspended,
	}
}

func requireApplied(t *testing.T, label string, result protocol.ResultEnvelope, err error) {
	t.Helper()

	requireStatus(t, label, result, err, protocol.StatusApplied)
}

func requireStatus(t *testing.T, label string, result protocol.ResultEnvelope, err error, want protocol.Status) {
	t.Helper()

	if err != nil {
		t.Fatalf("%s: Apply returned an error (no verdict reached): %v", label, err)
	}
	if result.Status != want {
		t.Fatalf("%s: expected status %s, got %s (errors=%+v)", label, want, result.Status, result.Errors)
	}
}

func requireErrorCode(t *testing.T, label string, result protocol.ResultEnvelope, code string) {
	t.Helper()

	for _, e := range result.Errors {
		if e.Code == code {
			return
		}
	}

	t.Fatalf("%s: expected an error with code %q, got %+v", label, code, result.Errors)
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

// --- tests ----------------------------------------------------------------

func TestDuplicateIdempotencyKeyReturnsAlreadyAppliedWithoutReRendering(t *testing.T) {
	d := newDisposableApache(t)
	capability := apache.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	idempotencyKey := newTestUUID()
	op := newOp(protocol.OperationCreate, resourceID, idempotencyKey, 1, apachePayload("dup.contract.test", "127.0.0.1", false))

	first, err := capability.Apply(ctx, op)
	requireApplied(t, "first create", first, err)

	// Re-submit the byte-identical envelope, as a real client retrying the
	// exact same request would. It must be recognized as a duplicate and
	// answered from the original receipt, never redoing the underlying
	// render/validate/activate/reload work: if it had, a fresh generation
	// would have been minted, and GenerationID would have moved on.
	second, err := capability.Apply(ctx, op)
	requireStatus(t, "duplicate create", second, err, protocol.StatusAlreadyApplied)

	if second.GenerationID != first.GenerationID {
		t.Fatalf("expected the duplicate to echo the original generation_id %q, got %q (implies re-rendering happened)", first.GenerationID, second.GenerationID)
	}
	if second.ObservedStateDigest != first.ObservedStateDigest {
		t.Fatalf("expected the duplicate to echo the original digest %q, got %q", first.ObservedStateDigest, second.ObservedStateDigest)
	}
}

// TestCreateSuspendUnsuspendServesRealHTTPContent independently re-verifies,
// with its own plain HTTP requests (not trusting ApacheCapability's own
// internal health check), that create/suspend/unsuspend really do change what
// a real apache2 process serves on a real port. Unlike nginx's own equivalent
// test, this deliberately does NOT assert digest equality between create and
// unsuspend: see this file's own top comment for why that would be wrong here.
func TestCreateSuspendUnsuspendServesRealHTTPContent(t *testing.T) {
	d := newDisposableApache(t)
	capability := apache.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "real-http.contract.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, apachePayload(domain, "127.0.0.1", false)))
	if err != nil || created.Status != protocol.StatusApplied {
		t.Fatalf("create: status=%s err=%v errors=%+v", created.Status, err, created.Errors)
	}

	body := getVhost(t, d.Port, domain)
	if !strings.Contains(body, "LESTA-MARKER") || !strings.Contains(body, resourceID) {
		t.Fatalf("expected the default vhost's own real HTTP response to contain its LESTA-MARKER and resource id, got: %q", body)
	}
	t.Logf("confirmed real HTTP 200 from the created vhost, body: %q", strings.TrimSpace(body))

	suspended, err := capability.Apply(ctx, newOp(protocol.OperationSuspend, resourceID, newTestUUID(), 2, apachePayload(domain, "127.0.0.1", true)))
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

	unsuspended, err := capability.Apply(ctx, newOp(protocol.OperationUnsuspend, resourceID, newTestUUID(), 3, apachePayload(domain, "127.0.0.1", false)))
	if err != nil || unsuspended.Status != protocol.StatusApplied {
		t.Fatalf("unsuspend: status=%s err=%v errors=%+v", unsuspended.Status, err, unsuspended.Errors)
	}

	body = getVhost(t, d.Port, domain)
	if !strings.Contains(body, "LESTA-MARKER") || !strings.Contains(body, resourceID) {
		t.Fatalf("expected unsuspend to restore the original vhost's real HTTP response, got: %q", body)
	}
	t.Logf("confirmed real HTTP 200 with the original page restored after unsuspend, body: %q", strings.TrimSpace(body))
}

// TestInvalidPayloadRejections groups every plain payload-validation
// rejection into one table, mirroring bind9_test.go's own
// TestInvalidPayloadRejections structure.
func TestInvalidPayloadRejections(t *testing.T) {
	d := newDisposableApache(t)
	capability := apache.New(d.Config)
	ctx := context.Background()

	t.Run("invalid domain", func(t *testing.T) {
		op := newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, apachePayload("not a valid domain!", "127.0.0.1", false))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "invalid domain", result, err, protocol.StatusRejected)
		requireErrorCode(t, "invalid domain", result, "invalid_domain")
	})

	t.Run("invalid ip address", func(t *testing.T) {
		op := newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, apachePayload("bad-ip.contract.test", "not-an-ip", false))
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "invalid ip address", result, err, protocol.StatusRejected)
		requireErrorCode(t, "invalid ip address", result, "invalid_ip_address")
	})

	t.Run("unsupported web template", func(t *testing.T) {
		payload := apachePayload("bad-template.contract.test", "127.0.0.1", false)
		payload["web_template"] = "apache-classic"
		op := newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, payload)
		result, err := capability.Apply(ctx, op)
		requireStatus(t, "unsupported web template", result, err, protocol.StatusRejected)
		requireErrorCode(t, "unsupported web template", result, "unsupported_web_template")
	})
}

// TestOperationsBeforeCreateAreRejectedUnknownResource mirrors
// bind9_test.go's own table-driven consolidation of every "operation X before
// create ever happened" contract case into one test.
func TestOperationsBeforeCreateAreRejectedUnknownResource(t *testing.T) {
	d := newDisposableApache(t)
	capability := apache.New(d.Config)
	ctx := context.Background()

	cases := []struct {
		name string
		op   protocol.Operation
	}{
		{"update", protocol.OperationUpdate},
		{"suspend", protocol.OperationSuspend},
		{"unsuspend", protocol.OperationUnsuspend},
		{"delete", protocol.OperationDelete},
		{"observe", protocol.OperationObserve},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			resourceID := newTestUUID()
			result, err := capability.Apply(ctx, newOp(tc.op, resourceID, newTestUUID(), 1, apachePayload("nope-"+tc.name+".contract.test", "127.0.0.1", tc.op == protocol.OperationSuspend)))
			requireStatus(t, tc.name+" before create", result, err, protocol.StatusRejected)
			requireErrorCode(t, tc.name+" before create", result, "unknown_resource")
		})
	}
}

func TestObserveAfterCreateReportsApplied(t *testing.T) {
	d := newDisposableApache(t)
	capability := apache.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "observe.contract.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, apachePayload(domain, "127.0.0.1", false)))
	requireApplied(t, "create", created, err)

	observed, err := capability.Apply(ctx, newOp(protocol.OperationObserve, resourceID, newTestUUID(), 1, apachePayload(domain, "127.0.0.1", false)))
	requireApplied(t, "observe", observed, err)

	if observed.ObservedStateDigest != created.ObservedStateDigest {
		t.Fatalf("expected observe to report the same digest create just established: create=%s observe=%s", created.ObservedStateDigest, observed.ObservedStateDigest)
	}
}

func TestCreateOnExistingResourceIsRejected(t *testing.T) {
	d := newDisposableApache(t)
	capability := apache.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "exists.contract.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, apachePayload(domain, "127.0.0.1", false)))
	requireApplied(t, "first create", created, err)

	second, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 2, apachePayload(domain, "127.0.0.1", false)))
	requireStatus(t, "second create on the same resource", second, err, protocol.StatusRejected)
	requireErrorCode(t, "second create on the same resource", second, "resource_already_exists")
}

func TestDeleteThenObserveReportsApplied(t *testing.T) {
	d := newDisposableApache(t)
	capability := apache.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "delete-then-observe.contract.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, apachePayload(domain, "127.0.0.1", false)))
	requireApplied(t, "create", created, err)

	deleted, err := capability.Apply(ctx, newOp(protocol.OperationDelete, resourceID, newTestUUID(), 2, apachePayload(domain, "127.0.0.1", false)))
	requireApplied(t, "delete", deleted, err)

	if deleted.ObservedStateDigest == created.ObservedStateDigest {
		t.Fatalf("expected delete to change the observed digest (the fragment is gone), both were %s", created.ObservedStateDigest)
	}

	// A resource this node has generation history for, even a deleted one, is
	// never unknown_resource: only a resource_id this node has never seen at
	// all is. observe reports applied because the live state (no fragment)
	// matches what delete's own manifest recorded.
	observed, err := capability.Apply(ctx, newOp(protocol.OperationObserve, resourceID, newTestUUID(), 2, apachePayload(domain, "127.0.0.1", false)))
	requireApplied(t, "observe after delete", observed, err)
}

// TestUpdateAfterCreateAppliedAndServesNewContent confirms update is applied
// and, via a real HTTP request, that the vhost now actually answers for the
// updated domain. It deliberately does NOT assert on the digest changing (see
// this file's own top comment): every operation here lands in a new
// generation, whose own ContentDir is necessarily different regardless of
// whether the domain actually changed, so a digest-changed assertion would
// hold trivially and prove nothing domain-specific.
func TestUpdateAfterCreateAppliedAndServesNewContent(t *testing.T) {
	d := newDisposableApache(t)
	capability := apache.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "update-before.contract.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, apachePayload(domain, "127.0.0.1", false)))
	requireApplied(t, "create", created, err)

	otherDomain := "update-after.contract.test"
	updated, err := capability.Apply(ctx, newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 2, apachePayload(otherDomain, "127.0.0.1", false)))
	requireApplied(t, "update", updated, err)

	if updated.ObservedStateVersion != 2 {
		t.Fatalf("expected observed_state_version to be 2 after update, got %d", updated.ObservedStateVersion)
	}

	body := getVhost(t, d.Port, otherDomain)
	if !strings.Contains(body, "LESTA-MARKER") || !strings.Contains(body, resourceID) {
		t.Fatalf("expected the updated vhost to serve its own marker for the new domain, got: %q", body)
	}
	t.Logf("confirmed real HTTP 200 for the updated domain after update, body: %q", strings.TrimSpace(body))
}

// TestDeleteRemovesLiveFragmentAndApacheStaysHealthy independently confirms,
// via a real filesystem check and a real HTTP request, that delete actually
// removes the live fragment and that apache2 is still serving other vhosts
// afterwards.
func TestDeleteRemovesLiveFragmentAndApacheStaysHealthy(t *testing.T) {
	d := newDisposableApache(t)
	capability := apache.New(d.Config)
	ctx := context.Background()

	survivorID := newTestUUID()
	survivorDomain := "survivor.contract.test"
	survivorResult, err := capability.Apply(ctx, newOp(protocol.OperationCreate, survivorID, newTestUUID(), 1, apachePayload(survivorDomain, "127.0.0.1", false)))
	if err != nil || survivorResult.Status != protocol.StatusApplied {
		t.Fatalf("creating survivor: status=%s err=%v", survivorResult.Status, err)
	}

	victimID := newTestUUID()
	victimDomain := "victim.contract.test"
	victimResult, err := capability.Apply(ctx, newOp(protocol.OperationCreate, victimID, newTestUUID(), 1, apachePayload(victimDomain, "127.0.0.1", false)))
	if err != nil || victimResult.Status != protocol.StatusApplied {
		t.Fatalf("creating victim: status=%s err=%v", victimResult.Status, err)
	}

	liveFragment := filepath.Join(d.Config.LiveDir, victimID+".conf")
	if _, err := os.Stat(liveFragment); err != nil {
		t.Fatalf("expected the victim's live fragment to exist before delete: %v", err)
	}

	deleted, err := capability.Apply(ctx, newOp(protocol.OperationDelete, victimID, newTestUUID(), 2, apachePayload(victimDomain, "127.0.0.1", false)))
	if err != nil || deleted.Status != protocol.StatusApplied {
		t.Fatalf("delete: status=%s err=%v errors=%+v", deleted.Status, err, deleted.Errors)
	}

	if _, err := os.Stat(liveFragment); !os.IsNotExist(err) {
		t.Fatalf("expected the victim's live fragment to be gone after delete, stat err=%v", err)
	}
	t.Logf("confirmed the live fragment %s no longer exists on disk after delete", liveFragment)

	// apache2 itself must still be healthy, serving the surviving vhost, a
	// real HTTP request away.
	body := getVhost(t, d.Port, survivorDomain)
	if !strings.Contains(body, survivorID) {
		t.Fatalf("expected the surviving vhost to still serve its own marker after the victim's delete, got: %q", body)
	}
	t.Logf("confirmed apache2 is still healthy and serving the surviving vhost after delete, body: %q", strings.TrimSpace(body))
}

// TestCreateFailureIsFailedNeverDegraded forces a real reload failure (via
// Config.ReloadCommand, the seam this phase's Config exposes for exactly this
// kind of substitution) for a brand-new resource. Since create has no prior
// generation to fall back to, the ADR's own table says this must be `failed`,
// never `degraded`, with no rollback attempted.
func TestCreateFailureIsFailedNeverDegraded(t *testing.T) {
	d := newDisposableApache(t)
	cfg := d.Config
	cfg.ReloadCommand = []string{"false"} // always fails, deterministically, no real reload ever happens

	capability := apache.New(cfg)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "create-failure.contract.test"

	result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, apachePayload(domain, "127.0.0.1", false)))
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
	d := newDisposableApache(t)
	capability := apache.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "update-double-failure.contract.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, apachePayload(domain, "127.0.0.1", false)))
	if err != nil || created.Status != protocol.StatusApplied {
		t.Fatalf("create: status=%s err=%v errors=%+v", created.Status, err, created.Errors)
	}

	// Now break reload entirely, for the update AND for its own rollback
	// attempt.
	cfg := d.Config
	cfg.ReloadCommand = []string{"false"}
	brokenCapability := apache.New(cfg)

	updated, err := brokenCapability.Apply(ctx, newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 2, apachePayload(domain, "127.0.0.1", false)))
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
// `<binary> -k graceful -d <prefix> -f <conf>` for every subsequent call,
// including the rollback's own. Real apache2 still does the actual
// validating, activating, reloading, and serving throughout; only the *signal
// that would fail* is deterministically scripted. This is also the test that
// most directly proves the required design property this capability shares
// with bind9 (see capability.go's own top comment): recoverFromFailure
// re-renders a fresh content file AND a fresh vhost fragment for the new
// rollback generation, rather than byte-copying the old generation's fragment
// forward -- explicitly verified below by confirming the rolled-back live
// fragment references its OWN new generation's content directory, not
// create's.
func TestUpdateFailureWithSuccessfulRollbackReportsDegraded(t *testing.T) {
	d := newDisposableApache(t)
	capability := apache.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "update-degraded.contract.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, apachePayload(domain, "127.0.0.1", false)))
	if err != nil || created.Status != protocol.StatusApplied {
		t.Fatalf("create: status=%s err=%v errors=%+v", created.Status, err, created.Errors)
	}

	body := getVhost(t, d.Port, domain)
	if !strings.Contains(body, resourceID) {
		t.Fatalf("expected the created vhost to serve its own marker before the forced failure, got: %q", body)
	}

	script := writeFailOnceReloadScript(t, d.binary)
	counterFile := filepath.Join(t.TempDir(), "counter")

	cfg := d.Config
	cfg.ReloadCommand = []string{
		"sh", script, counterFile, "1",
		"-d", d.Prefix, "-f", d.Config.ApacheConfPath, "-k", "graceful",
	}
	flakyCapability := apache.New(cfg)

	updated, err := flakyCapability.Apply(ctx, newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 2, apachePayload("changed-"+domain, "127.0.0.1", false)))
	if err != nil {
		t.Fatalf("Apply returned an error (no verdict reached): %v", err)
	}
	if updated.Status != protocol.StatusDegraded {
		t.Fatalf("expected a recoverable reload failure to report degraded, got %s (errors=%+v)", updated.Status, updated.Errors)
	}
	t.Logf("confirmed a real, recoverable reload failure reports degraded: status=%s errors=%+v", updated.Status, updated.Errors)

	if updated.GenerationID == created.GenerationID {
		t.Fatalf("expected the rollback to land in a new generation, got the same generation_id %q as create", created.GenerationID)
	}

	// The rolled-back live fragment must reference the NEW rollback
	// generation's own content directory, and that directory must have a
	// freshly-written content file of its own -- never create's original
	// generation directory (which generation.Store's own pruning could
	// eventually delete out from under a byte-copied-forward reference).
	newGenerationDir := filepath.Join(d.Config.StateRoot, "domains", resourceID, "generations", updated.GenerationID)

	liveFragment, err := os.ReadFile(filepath.Join(d.Config.LiveDir, resourceID+".conf"))
	if err != nil {
		t.Fatalf("reading live fragment after rollback: %v", err)
	}
	if !strings.Contains(string(liveFragment), newGenerationDir) {
		t.Fatalf("expected the rolled-back live fragment to reference its OWN generation's content directory %s, got:\n%s", newGenerationDir, liveFragment)
	}
	if _, err := os.Stat(filepath.Join(newGenerationDir, "content")); err != nil {
		t.Fatalf("expected a freshly-written content file under %s for the rolled-back generation: %v", newGenerationDir, err)
	}

	// Independently re-confirm, with our own real HTTP request, that the
	// ORIGINAL domain is still being served (last-known-good), not the
	// attempted (and rolled back) new one.
	body = getVhost(t, d.Port, domain)
	if !strings.Contains(body, resourceID) {
		t.Fatalf("expected the original vhost to still be served for real after a degraded rollback, got: %q", body)
	}
	t.Logf("confirmed via a real HTTP request that the original vhost is still being served after the degraded rollback, body: %q", strings.TrimSpace(body))
}

// TestWriteModulesFragmentIsIdempotent creates two resources back-to-back and
// confirms 00-lesta-modules.conf is written once with stable, byte-identical
// content across both applyGeneration calls, and that a real apache2 instance
// never errors (only, at most, warns) about the module already being loaded
// across repeated reloads. There is no nginx or bind9 analog: mod_asis's own
// enablement fragment is unique to this capability (see content.go's
// ensureModulesFragment).
func TestWriteModulesFragmentIsIdempotent(t *testing.T) {
	d := newDisposableApache(t)
	capability := apache.New(d.Config)
	ctx := context.Background()

	modulesFragmentPath := filepath.Join(d.Config.LiveDir, "00-lesta-modules.conf")

	firstID := newTestUUID()
	first, err := capability.Apply(ctx, newOp(protocol.OperationCreate, firstID, newTestUUID(), 1, apachePayload("modules-first.contract.test", "127.0.0.1", false)))
	requireApplied(t, "create first resource", first, err)

	firstContent, err := os.ReadFile(modulesFragmentPath)
	if err != nil {
		t.Fatalf("reading modules fragment after first create: %v", err)
	}

	secondID := newTestUUID()
	second, err := capability.Apply(ctx, newOp(protocol.OperationCreate, secondID, newTestUUID(), 1, apachePayload("modules-second.contract.test", "127.0.0.1", false)))
	requireApplied(t, "create second resource", second, err)

	secondContent, err := os.ReadFile(modulesFragmentPath)
	if err != nil {
		t.Fatalf("reading modules fragment after second create: %v", err)
	}

	if string(firstContent) != string(secondContent) {
		t.Fatalf("expected the modules fragment's content to stay byte-identical across repeated applyGeneration calls:\nfirst:\n%s\nsecond:\n%s", firstContent, secondContent)
	}
	t.Logf("confirmed 00-lesta-modules.conf stayed byte-identical across two applyGeneration calls, and both creates reported applied (a real apache2 reload never errored)")
}

func writeFailOnceReloadScript(t *testing.T, binary string) string {
	t.Helper()

	path := filepath.Join(t.TempDir(), "flaky-reload.sh")
	script := fmt.Sprintf(`#!/bin/sh
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
exec %s "$@"
`, binary)
	if err := os.WriteFile(path, []byte(script), 0o755); err != nil {
		t.Fatalf("writing flaky reload script: %v", err)
	}

	return path
}
