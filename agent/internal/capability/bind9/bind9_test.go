package bind9_test

import (
	"bytes"
	"context"
	"crypto/rand"
	"encoding/json"
	"errors"
	"fmt"
	"net"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/bind9"
	"github.com/mikho/LESta/agent/internal/generation"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// --- envelope/payload construction helpers -------------------------------

func newTestUUID() string {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		panic(fmt.Sprintf("generating test UUID: %v", err))
	}

	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80

	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16])
}

func newOp(operation protocol.Operation, resourceID, idempotencyKey string, desiredStateVersion int, payload bind9.Payload) protocol.OperationEnvelope {
	raw, err := json.Marshal(payload)
	if err != nil {
		panic(fmt.Sprintf("marshaling test payload: %v", err))
	}

	now := time.Now().UTC()

	return protocol.OperationEnvelope{
		ProtocolVersion:     "1",
		Capability:          "dns.bind9.v1",
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

func intPtr(n int) *int { return &n }

func simplePayload(domain string, records []bind9.Record, suspended bool) bind9.Payload {
	return bind9.Payload{Domain: domain, TTL: 3600, Records: records, Suspended: suspended}
}

// --- real-network confirmation helpers -----------------------------------

// resolverAt returns a net.Resolver pinned at the disposable instance's own
// port via a custom Dial, the same well-established stdlib pattern the
// production waitHealthy implementation uses.
func resolverAt(port int) *net.Resolver {
	return &net.Resolver{
		PreferGo: true,
		Dial: func(ctx context.Context, network, _ string) (net.Conn, error) {
			var d net.Dialer

			return d.DialContext(ctx, network, fmt.Sprintf("127.0.0.1:%d", port))
		},
	}
}

func lookupMarker(t *testing.T, port int, domain string) []string {
	t.Helper()

	ctx, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	txt, err := resolverAt(port).LookupTXT(ctx, "_lesta-marker."+domain+".")
	if err != nil {
		t.Fatalf("looking up marker TXT for %s: %v", domain, err)
	}

	return txt
}

// buildDNSQuery hand-builds a minimal RFC 1035 DNS query for qname (type A,
// class IN). Used only by queryRcode below, to inspect a response's raw
// RCODE directly rather than relying on how Go's stdlib resolver classifies
// non-success RCODEs into errors, which turned out (verified directly
// against this package's own disposable harness) to collapse REFUSED into
// the same generic "server misbehaving" *net.DNSError text used for other,
// unrelated failure modes -- too ambiguous a signal to assert on.
func buildDNSQuery(qname string) []byte {
	var buf bytes.Buffer

	buf.Write([]byte{0x12, 0x34, 0x01, 0x00, 0x00, 0x01, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00})

	for _, label := range strings.Split(strings.TrimSuffix(qname, "."), ".") {
		buf.WriteByte(byte(len(label)))
		buf.WriteString(label)
	}

	buf.WriteByte(0)
	buf.Write([]byte{0x00, 0x01, 0x00, 0x01}) // QTYPE=A, QCLASS=IN

	return buf.Bytes()
}

// queryRcode sends a raw DNS query for qname to the disposable instance and
// returns the response's RCODE (the low 4 bits of header byte 3): 0 is
// NOERROR, 3 is NXDOMAIN, 5 is REFUSED.
func queryRcode(t *testing.T, port int, qname string) int {
	t.Helper()

	conn, err := net.Dial("udp", fmt.Sprintf("127.0.0.1:%d", port))
	if err != nil {
		t.Fatalf("dialing disposable named: %v", err)
	}
	defer conn.Close()

	if err := conn.SetDeadline(time.Now().Add(3 * time.Second)); err != nil {
		t.Fatalf("setting query deadline: %v", err)
	}

	if _, err := conn.Write(buildDNSQuery(qname)); err != nil {
		t.Fatalf("sending query for %s: %v", qname, err)
	}

	resp := make([]byte, 512)

	n, err := conn.Read(resp)
	if err != nil {
		t.Fatalf("reading response for %s: %v", qname, err)
	}
	if n < 4 {
		t.Fatalf("short DNS response for %s: %d bytes", qname, n)
	}

	return int(resp[3] & 0x0f)
}

const (
	rcodeNoError  = 0
	rcodeNXDomain = 3
	rcodeRefused  = 5
)

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

// --- generation history inspection (constructs its own generation.Store
// pointed at the same root the capability under test uses) ----------------

func manifestFor(t *testing.T, stateRoot, resourceID string) generation.Manifest {
	t.Helper()

	store := generation.New(stateRoot + "/zones")

	n, ok, err := store.CurrentGeneration(resourceID)
	if err != nil {
		t.Fatalf("reading current generation for %s: %v", resourceID, err)
	}
	if !ok {
		t.Fatalf("expected %s to have generation history", resourceID)
	}

	manifest, err := store.ReadManifest(resourceID, n)
	if err != nil {
		t.Fatalf("reading manifest for %s generation %d: %v", resourceID, n, err)
	}

	return manifest
}

// --- tests ----------------------------------------------------------------

func TestDuplicateIdempotencyKeyReturnsAlreadyAppliedWithoutReRendering(t *testing.T) {
	requireRealBind9(t)

	d := newDisposableBind9(t)
	capability := bind9.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	idempotencyKey := newTestUUID()
	op := newOp(protocol.OperationCreate, resourceID, idempotencyKey, 1, simplePayload("dup.test", nil, false))

	first, err := capability.Apply(ctx, op)
	requireApplied(t, "first create", first, err)

	second, err := capability.Apply(ctx, op)
	requireStatus(t, "duplicate create", second, err, protocol.StatusAlreadyApplied)

	if second.GenerationID != first.GenerationID {
		t.Fatalf("expected the duplicate to echo the original generation_id %q, got %q (implies re-rendering happened)", first.GenerationID, second.GenerationID)
	}
	if second.ObservedStateDigest != first.ObservedStateDigest {
		t.Fatalf("expected the duplicate to echo the original digest %q, got %q", first.ObservedStateDigest, second.ObservedStateDigest)
	}
}

// TestCreateMultiRecordServesRealContent creates a zone with at least one of
// each of the 9 supported record types and confirms, via real DNS queries,
// that the marker and real record content are actually being served. The
// TXT record's value deliberately embeds a quote and a backslash, so this
// test doubles as the plan's required regression check that escaped TXT
// output is real, valid BIND zone syntax (named-checkconf -z runs as part
// of Apply's own validateCandidate step; if the escaping were wrong, this
// create would be rejected, not merely mis-rendered).
func TestCreateMultiRecordServesRealContent(t *testing.T) {
	requireRealBind9(t)

	d := newDisposableBind9(t)
	capability := bind9.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "multi.test"

	records := []bind9.Record{
		{Name: "www", Type: "A", Value: "203.0.113.10"},
		{Name: "www6", Type: "AAAA", Value: "2001:db8::10"},
		{Name: "alias", Type: "CNAME", Value: "www.multi.test"},
		{Name: "@", Type: "MX", Priority: intPtr(10), Value: "mail.multi.test"},
		{Name: "@", Type: "TXT", Value: `v=spf1 include:"weird\path" ~all`},
		{Name: "_sip._tcp", Type: "SRV", Priority: intPtr(20), Value: "10 5060 sipserver.multi.test."},
		{Name: "10", Type: "PTR", Value: "host.multi.test"},
		{Name: "@", Type: "CAA", Value: `0 issue "letsencrypt.org"`},
		{Name: "sub", Type: "NS", Value: "ns1.lesta-hosting.test."},
	}

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, simplePayload(domain, records, false)))
	requireApplied(t, "create", created, err)

	marker := lookupMarker(t, d.Port, domain)
	found := false
	for _, m := range marker {
		if strings.Contains(m, "resource="+resourceID) {
			found = true
		}
	}
	if !found {
		t.Fatalf("expected marker TXT to contain resource=%s, got %v", resourceID, marker)
	}

	ctxQ, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	addrs, err := resolverAt(d.Port).LookupHost(ctxQ, "www."+domain+".")
	if err != nil {
		t.Fatalf("looking up www.%s: %v", domain, err)
	}
	found = false
	for _, a := range addrs {
		if a == "203.0.113.10" {
			found = true
		}
	}
	if !found {
		t.Fatalf("expected www.%s to resolve to 203.0.113.10, got %v", domain, addrs)
	}
}

// TestCreateZeroRecordsZone confirms the finding this phase's plan verified
// directly against the local BIND9 install: an out-of-bailiwick NS set needs
// no glue, so a zone with zero DnsRecord rows still validates (implicitly,
// since create only succeeds if named-checkconf -z passes) and serves (a
// real marker query succeeds).
func TestCreateZeroRecordsZone(t *testing.T) {
	requireRealBind9(t)

	d := newDisposableBind9(t)
	capability := bind9.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "empty.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, simplePayload(domain, nil, false)))
	requireApplied(t, "create with zero records", created, err)

	marker := lookupMarker(t, d.Port, domain)
	found := false
	for _, m := range marker {
		if strings.Contains(m, "resource="+resourceID) {
			found = true
		}
	}
	if !found {
		t.Fatalf("expected marker TXT to contain resource=%s, got %v", resourceID, marker)
	}
}

// TestCreateSuspendedFromStart confirms a zone can be created already
// suspended (payload.Suspended=true on the very first, create-shaped call):
// applied, but with no live stanza at all, and REFUSED for any query.
func TestCreateSuspendedFromStart(t *testing.T) {
	requireRealBind9(t)

	d := newDisposableBind9(t)
	capability := bind9.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "suspended-from-start.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, simplePayload(domain, nil, true)))
	requireApplied(t, "create suspended from start", created, err)

	if _, err := os.Stat(filepath.Join(d.Config.LiveDir, resourceID+".conf")); !os.IsNotExist(err) {
		t.Fatalf("expected no live stanza for a zone created already suspended, stat err=%v", err)
	}

	if rc := queryRcode(t, d.Port, domain+"."); rc != rcodeRefused {
		t.Fatalf("expected REFUSED (%d) for a suspended-from-start zone, got rcode %d", rcodeRefused, rc)
	}

	manifest := manifestFor(t, d.Config.StateRoot, resourceID)
	if !manifest.Deleted {
		t.Fatalf("expected the very first generation of a suspended-from-start zone to be recorded deleted=true")
	}
}

func TestUpdateChangingRecordsChangesServedContent(t *testing.T) {
	requireRealBind9(t)

	d := newDisposableBind9(t)
	capability := bind9.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "update.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1,
		simplePayload(domain, []bind9.Record{{Name: "www", Type: "A", Value: "203.0.113.1"}}, false)))
	requireApplied(t, "create", created, err)

	ctxQ, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	addrs, err := resolverAt(d.Port).LookupHost(ctxQ, "www."+domain+".")
	if err != nil || len(addrs) == 0 || addrs[0] != "203.0.113.1" {
		t.Fatalf("expected www.%s to resolve to 203.0.113.1 before update, got %v (err=%v)", domain, addrs, err)
	}

	updated, err := capability.Apply(ctx, newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 2,
		simplePayload(domain, []bind9.Record{{Name: "www", Type: "A", Value: "203.0.113.2"}}, false)))
	requireApplied(t, "update", updated, err)

	if updated.ObservedStateDigest == created.ObservedStateDigest {
		t.Fatalf("expected update to change the observed digest, both were %s", created.ObservedStateDigest)
	}

	ctxQ2, cancel2 := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel2()

	addrs2, err := resolverAt(d.Port).LookupHost(ctxQ2, "www."+domain+".")
	if err != nil || len(addrs2) == 0 || addrs2[0] != "203.0.113.2" {
		t.Fatalf("expected www.%s to resolve to 203.0.113.2 after update, got %v (err=%v)", domain, addrs2, err)
	}
	for _, a := range addrs2 {
		if a == "203.0.113.1" {
			t.Fatalf("expected the old address 203.0.113.1 to no longer be served after update, got %v", addrs2)
		}
	}
}

func TestSuspendExistingZoneRemovesStanzaAndRefuses(t *testing.T) {
	requireRealBind9(t)

	d := newDisposableBind9(t)
	capability := bind9.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "suspend.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, simplePayload(domain, nil, false)))
	requireApplied(t, "create", created, err)

	suspended, err := capability.Apply(ctx, newOp(protocol.OperationSuspend, resourceID, newTestUUID(), 2, simplePayload(domain, nil, true)))
	requireApplied(t, "suspend", suspended, err)

	if _, err := os.Stat(filepath.Join(d.Config.LiveDir, resourceID+".conf")); !os.IsNotExist(err) {
		t.Fatalf("expected the live stanza to be removed after suspend, stat err=%v", err)
	}

	if rc := queryRcode(t, d.Port, domain+"."); rc != rcodeRefused {
		t.Fatalf("expected REFUSED (%d) after suspend, got rcode %d", rcodeRefused, rc)
	}

	manifest := manifestFor(t, d.Config.StateRoot, resourceID)
	if !manifest.Deleted {
		t.Fatalf("expected the generation created by suspend to be recorded deleted=true")
	}
}

// TestUnsuspendRestoresContent confirms records serve again with the same
// content as before suspension. This is confirmed via a real follow-up
// query, NOT via digest identity: unlike nginx (where create-suspend-
// unsuspend restores the exact same digest, since nginx's suspended state
// still renders an alternate template whose own bytes are stable), bind9's
// stanza embeds its own generation-specific zone.db path, which necessarily
// differs across generations by construction. Digest identity can never
// hold here, so the DNS-meaningful invariant (same content actually served)
// is what's asserted instead.
func TestUnsuspendRestoresContent(t *testing.T) {
	requireRealBind9(t)

	d := newDisposableBind9(t)
	capability := bind9.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "unsuspend.test"
	records := []bind9.Record{{Name: "www", Type: "A", Value: "203.0.113.5"}}

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, simplePayload(domain, records, false)))
	requireApplied(t, "create", created, err)

	suspended, err := capability.Apply(ctx, newOp(protocol.OperationSuspend, resourceID, newTestUUID(), 2, simplePayload(domain, records, true)))
	requireApplied(t, "suspend", suspended, err)

	unsuspended, err := capability.Apply(ctx, newOp(protocol.OperationUnsuspend, resourceID, newTestUUID(), 3, simplePayload(domain, records, false)))
	requireApplied(t, "unsuspend", unsuspended, err)

	// Deliberately NOT asserting unsuspended.ObservedStateDigest ==
	// created.ObservedStateDigest here; see the doc comment above.

	ctxQ, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	addrs, err := resolverAt(d.Port).LookupHost(ctxQ, "www."+domain+".")
	if err != nil || len(addrs) == 0 || addrs[0] != "203.0.113.5" {
		t.Fatalf("expected www.%s to resolve to 203.0.113.5 after unsuspend, got %v (err=%v)", domain, addrs, err)
	}
}

func TestPerRecordSuspendOnlyServesNonSuspended(t *testing.T) {
	requireRealBind9(t)

	d := newDisposableBind9(t)
	capability := bind9.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "per-record.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1,
		simplePayload(domain, []bind9.Record{
			{Name: "active", Type: "A", Value: "203.0.113.20"},
			{Name: "inactive", Type: "A", Value: "203.0.113.21"},
		}, false)))
	requireApplied(t, "create", created, err)

	updated, err := capability.Apply(ctx, newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 2,
		simplePayload(domain, []bind9.Record{
			{Name: "active", Type: "A", Value: "203.0.113.20"},
			{Name: "inactive", Type: "A", Value: "203.0.113.21", Suspended: true},
		}, false)))
	requireApplied(t, "update with one record suspended", updated, err)

	ctxQ, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	addrs, err := resolverAt(d.Port).LookupHost(ctxQ, "active."+domain+".")
	if err != nil || len(addrs) == 0 || addrs[0] != "203.0.113.20" {
		t.Fatalf("expected active.%s to still resolve, got %v (err=%v)", domain, addrs, err)
	}

	ctxQ2, cancel2 := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel2()

	_, err = resolverAt(d.Port).LookupHost(ctxQ2, "inactive."+domain+".")
	var dnsErr *net.DNSError
	if !errors.As(err, &dnsErr) || !dnsErr.IsNotFound {
		t.Fatalf("expected inactive.%s (per-record suspended) to NOT resolve (NXDOMAIN), got err=%v", domain, err)
	}
}

func TestDeleteRemovesStanzaAndObserveAfterwardsReportsApplied(t *testing.T) {
	requireRealBind9(t)

	d := newDisposableBind9(t)
	capability := bind9.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "delete.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, simplePayload(domain, nil, false)))
	requireApplied(t, "create", created, err)

	deleted, err := capability.Apply(ctx, newOp(protocol.OperationDelete, resourceID, newTestUUID(), 2, simplePayload(domain, nil, false)))
	requireApplied(t, "delete", deleted, err)

	if _, err := os.Stat(filepath.Join(d.Config.LiveDir, resourceID+".conf")); !os.IsNotExist(err) {
		t.Fatalf("expected the live stanza to be gone after delete, stat err=%v", err)
	}

	if rc := queryRcode(t, d.Port, domain+"."); rc != rcodeRefused {
		t.Fatalf("expected REFUSED (%d) after delete, got rcode %d", rcodeRefused, rc)
	}

	observed, err := capability.Apply(ctx, newOp(protocol.OperationObserve, resourceID, newTestUUID(), 2, simplePayload(domain, nil, false)))
	requireApplied(t, "observe after delete", observed, err)
}

func TestObserveHealthyThenDriftDetected(t *testing.T) {
	requireRealBind9(t)

	d := newDisposableBind9(t)
	capability := bind9.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "observe.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, simplePayload(domain, nil, false)))
	requireApplied(t, "create", created, err)

	observed, err := capability.Apply(ctx, newOp(protocol.OperationObserve, resourceID, newTestUUID(), 1, simplePayload(domain, nil, false)))
	requireApplied(t, "observe (healthy)", observed, err)

	// Hand-edit the live stanza directly, simulating out-of-band drift.
	// observe() never touches named itself (it only compares filesystem
	// digests), so no reload is needed to make this drift observable.
	livePath := filepath.Join(d.Config.LiveDir, resourceID+".conf")
	original, err := os.ReadFile(livePath)
	if err != nil {
		t.Fatalf("reading live stanza: %v", err)
	}
	if err := os.WriteFile(livePath, append(original, []byte("\n# hand-edited\n")...), 0o644); err != nil {
		t.Fatalf("hand-editing live stanza: %v", err)
	}

	drifted, err := capability.Apply(ctx, newOp(protocol.OperationObserve, resourceID, newTestUUID(), 1, simplePayload(domain, nil, false)))
	requireStatus(t, "observe (drifted)", drifted, err, protocol.StatusDegraded)
	requireErrorCode(t, "observe (drifted)", drifted, "drift_detected")
}

func TestOperationsBeforeCreateAreRejectedUnknownResource(t *testing.T) {
	requireRealBind9(t)

	d := newDisposableBind9(t)
	capability := bind9.New(d.Config)
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
			result, err := capability.Apply(ctx, newOp(tc.op, resourceID, newTestUUID(), 1, simplePayload("nope-"+tc.name+".test", nil, false)))
			requireStatus(t, tc.name+" before create", result, err, protocol.StatusRejected)
			requireErrorCode(t, tc.name+" before create", result, "unknown_resource")
		})
	}
}

func TestCreateOnExistingResourceIsRejected(t *testing.T) {
	requireRealBind9(t)

	d := newDisposableBind9(t)
	capability := bind9.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "exists.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, simplePayload(domain, nil, false)))
	requireApplied(t, "first create", created, err)

	second, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 2, simplePayload(domain, nil, false)))
	requireStatus(t, "second create on the same resource", second, err, protocol.StatusRejected)
	requireErrorCode(t, "second create on the same resource", second, "resource_already_exists")
}

func TestInvalidPayloadRejections(t *testing.T) {
	requireRealBind9(t)

	d := newDisposableBind9(t)
	capability := bind9.New(d.Config)
	ctx := context.Background()

	t.Run("invalid domain", func(t *testing.T) {
		result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, simplePayload("not a valid domain!", nil, false)))
		requireStatus(t, "invalid domain", result, err, protocol.StatusRejected)
		requireErrorCode(t, "invalid domain", result, "invalid_domain")
	})

	t.Run("ttl below minimum", func(t *testing.T) {
		p := simplePayload("ttl-low.test", nil, false)
		p.TTL = 1
		result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, p))
		requireStatus(t, "ttl below minimum", result, err, protocol.StatusRejected)
		requireErrorCode(t, "ttl below minimum", result, "ttl_out_of_range")
	})

	t.Run("ttl above maximum", func(t *testing.T) {
		p := simplePayload("ttl-high.test", nil, false)
		p.TTL = 10_000_000
		result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, p))
		requireStatus(t, "ttl above maximum", result, err, protocol.StatusRejected)
		requireErrorCode(t, "ttl above maximum", result, "ttl_out_of_range")
	})

	t.Run("unknown record type", func(t *testing.T) {
		p := simplePayload("unknown-type.test", []bind9.Record{{Name: "www", Type: "BOGUS", Value: "x"}}, false)
		result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, p))
		requireStatus(t, "unknown record type", result, err, protocol.StatusRejected)
		requireErrorCode(t, "unknown record type", result, "unknown_record_type")
	})

	t.Run("priority present for non-MX/SRV type", func(t *testing.T) {
		p := simplePayload("priority-not-allowed.test", []bind9.Record{{Name: "www", Type: "A", Value: "203.0.113.1", Priority: intPtr(5)}}, false)
		result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, p))
		requireStatus(t, "priority present for A", result, err, protocol.StatusRejected)
		requireErrorCode(t, "priority present for A", result, "priority_not_allowed")
	})

	t.Run("priority missing for MX", func(t *testing.T) {
		p := simplePayload("priority-required.test", []bind9.Record{{Name: "@", Type: "MX", Value: "mail.example.com"}}, false)
		result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, p))
		requireStatus(t, "priority missing for MX", result, err, protocol.StatusRejected)
		requireErrorCode(t, "priority missing for MX", result, "priority_required")
	})

	t.Run("reserved marker record name", func(t *testing.T) {
		p := simplePayload("reserved-name.test", []bind9.Record{{Name: "_lesta-marker", Type: "TXT", Value: "hijack"}}, false)
		result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, p))
		requireStatus(t, "reserved marker record name", result, err, protocol.StatusRejected)
		requireErrorCode(t, "reserved marker record name", result, "reserved_record_name")
	})
}

// TestCreateFailureIsFailedNeverDegraded forces a real reload failure (via
// Config.ReloadCommand) for a brand-new resource. Since create has no prior
// generation to fall back to, this must be failed, never degraded, with no
// rollback attempted.
func TestCreateFailureIsFailedNeverDegraded(t *testing.T) {
	requireRealBind9(t)

	d := newDisposableBind9(t)
	cfg := d.Config
	cfg.ReloadCommand = []string{"false"}
	capability := bind9.New(cfg)
	ctx := context.Background()

	resourceID := newTestUUID()
	result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, simplePayload("create-failure.test", nil, false)))
	if err != nil {
		t.Fatalf("Apply returned an error (no verdict reached): %v", err)
	}
	if result.Status != protocol.StatusFailed {
		t.Fatalf("expected create's reload failure to be reported as failed, got %s (errors=%+v)", result.Status, result.Errors)
	}
}

// TestUpdateFailureWithFailingRollbackReportsFailed forces every reload call
// to fail, including the rollback's own; failed is reported only when even
// the rollback itself cannot recover.
func TestUpdateFailureWithFailingRollbackReportsFailed(t *testing.T) {
	requireRealBind9(t)

	d := newDisposableBind9(t)
	capability := bind9.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "update-double-failure.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, simplePayload(domain, nil, false)))
	requireApplied(t, "create", created, err)

	cfg := d.Config
	cfg.ReloadCommand = []string{"false"}
	brokenCapability := bind9.New(cfg)

	updated, err := brokenCapability.Apply(ctx, newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 2, simplePayload(domain, nil, false)))
	if err != nil {
		t.Fatalf("Apply returned an error (no verdict reached): %v", err)
	}
	if updated.Status != protocol.StatusFailed {
		t.Fatalf("expected a doubly-failing rollback to report failed, got %s (errors=%+v)", updated.Status, updated.Errors)
	}
}

// TestUpdateFailureWithSuccessfulRollbackReportsDegraded uses a counting
// shell wrapper as Config.ReloadCommand: it fails exactly once (simulating a
// transient real reload failure for the update), then delegates to the real
// `rndc reload` for every subsequent call, including the rollback's own.
// This is also the test that most directly proves the required bug fix
// (recoverFromFailure re-rendering a fresh zone.db for the new generation,
// rather than byte-copying the old generation's stanza forward): it
// explicitly verifies the rollback's new generation directory has its own
// freshly-written zone.db.
func TestUpdateFailureWithSuccessfulRollbackReportsDegraded(t *testing.T) {
	requireRealBind9(t)

	d := newDisposableBind9(t)
	capability := bind9.New(d.Config)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "update-degraded.test"
	records := []bind9.Record{{Name: "www", Type: "A", Value: "203.0.113.9"}}

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, simplePayload(domain, records, false)))
	requireApplied(t, "create", created, err)

	script := writeFailOnceReloadScript(t)
	counterFile := filepath.Join(t.TempDir(), "counter")

	cfg := d.Config
	cfg.ReloadCommand = []string{"sh", script, counterFile, "1", "-c", d.Config.RndcConfigPath, "reload"}
	flakyCapability := bind9.New(cfg)

	updated, err := flakyCapability.Apply(ctx, newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 2,
		simplePayload(domain, []bind9.Record{{Name: "www", Type: "A", Value: "203.0.113.99"}}, false)))
	if err != nil {
		t.Fatalf("Apply returned an error (no verdict reached): %v", err)
	}
	if updated.Status != protocol.StatusDegraded {
		t.Fatalf("expected a recoverable reload failure to report degraded, got %s (errors=%+v)", updated.Status, updated.Errors)
	}

	// The rolled-back generation must be a NEW generation number (not create's
	// original one), and its live stanza's `file` directive must point at
	// THAT new generation's own freshly-written zone.db, not create's.
	if updated.GenerationID == created.GenerationID {
		t.Fatalf("expected the rollback to land in a new generation, got the same generation_id %q as create", created.GenerationID)
	}

	store := generation.New(d.Config.StateRoot + "/zones")
	expectedZoneDB := filepath.Join(store.GenerationDir(resourceID, mustAtoi(t, updated.GenerationID)), "zone.db")

	liveStanza, err := os.ReadFile(filepath.Join(d.Config.LiveDir, resourceID+".conf"))
	if err != nil {
		t.Fatalf("reading live stanza after rollback: %v", err)
	}
	if !strings.Contains(string(liveStanza), expectedZoneDB) {
		t.Fatalf("expected the rolled-back live stanza to reference its OWN generation's zone.db path %s, got:\n%s", expectedZoneDB, liveStanza)
	}
	if _, err := os.Stat(expectedZoneDB); err != nil {
		t.Fatalf("expected a freshly-written zone.db at %s for the rolled-back generation: %v", expectedZoneDB, err)
	}

	// Independently re-confirm, with our own real DNS query, that the
	// ORIGINAL content is still being served (last-known-good).
	ctxQ, cancel := context.WithTimeout(context.Background(), 5*time.Second)
	defer cancel()

	addrs, err := resolverAt(d.Port).LookupHost(ctxQ, "www."+domain+".")
	if err != nil || len(addrs) == 0 || addrs[0] != "203.0.113.9" {
		t.Fatalf("expected the original content (203.0.113.9) to still be served after a degraded rollback, got %v (err=%v)", addrs, err)
	}
}

func mustAtoi(t *testing.T, s string) int {
	t.Helper()

	var n int
	if _, err := fmt.Sscanf(s, "%d", &n); err != nil {
		t.Fatalf("parsing generation id %q as an int: %v", s, err)
	}

	return n
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
exec rndc "$@"
`
	if err := os.WriteFile(path, []byte(script), 0o755); err != nil {
		t.Fatalf("writing flaky reload script: %v", err)
	}

	return path
}
