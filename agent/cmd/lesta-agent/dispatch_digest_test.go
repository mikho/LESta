package main

import (
	"context"
	"encoding/json"
	"os"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/protocol"
)

func TestDispatchOperationRejectsAnOperationWhoseDigestDoesNotMatch(t *testing.T) {
	op := protocol.OperationEnvelope{
		ProtocolVersion:     "1",
		Capability:          "web.nginx.v1",
		Operation:           protocol.OperationUpdate,
		ResourceID:          "11111111-1111-1111-1111-111111111111",
		DesiredStateVersion: 3,
		IdempotencyKey:      "22222222-2222-2222-2222-222222222222",
		CorrelationID:       "33333333-3333-3333-3333-333333333333",
		Payload:             json.RawMessage(`{"domain":"example.com"}`),
	}

	digest, err := protocol.RequestDigest(op)
	if err != nil {
		t.Fatal(err)
	}

	// The payload is changed after the panel computed the digest.
	op.RequestDigest = digest
	op.Payload = json.RawMessage(`{"domain":"example.org"}`)

	result, err := dispatchOperation(context.Background(), op)
	if err != nil {
		t.Fatalf("a mismatch is a rejected result, not an error: %v", err)
	}

	if result.Status != protocol.StatusRejected {
		t.Fatalf("status = %s, want rejected", result.Status)
	}

	if len(result.Errors) != 1 || result.Errors[0].Code != "request_digest_mismatch" {
		t.Fatalf("errors = %+v", result.Errors)
	}

	if result.IdempotencyKey != op.IdempotencyKey || result.CorrelationID != op.CorrelationID {
		t.Fatalf("the result does not answer the operation: %+v", result)
	}
}

// The installers' self-tests send an envelope without a digest to the one-shot stdin mode, which
// seals it; a digest that is there but wrong is never replaced.
func TestRunSealsAnEnvelopeWithoutDigestAndKeepsAWrongOne(t *testing.T) {
	envelope := func(digest string) *os.File {
		body := `{"protocol_version":"1","capability":"web.nginx.v1","operation":"update","resource_id":"11111111-1111-1111-1111-111111111111","desired_state_version":3,"idempotency_key":"22222222-2222-2222-2222-222222222222","correlation_id":"33333333-3333-3333-3333-333333333333","deadline":"2026-12-01T00:00:00Z","issued_at":"2026-11-12T00:00:00Z","request_digest":"` + digest + `","payload":{"domain":"example.com"}}`

		f, err := os.CreateTemp(t.TempDir(), "stdin")
		if err != nil {
			t.Fatal(err)
		}

		if _, err := f.WriteString(body); err != nil {
			t.Fatal(err)
		}

		if _, err := f.Seek(0, 0); err != nil {
			t.Fatal(err)
		}

		return f
	}

	out, err := os.CreateTemp(t.TempDir(), "stdout")
	if err != nil {
		t.Fatal(err)
	}

	// Sealed, so it is dispatched: web.nginx.v1 is hosted by an applier, whose socket is absent here,
	// and that failure (not a digest rejection) is what shows it got past verification.
	err = run(envelope(""), out)
	if err == nil || strings.Contains(err.Error(), "request_digest") {
		t.Fatalf("an envelope without digest should be sealed and dispatched, got %v", err)
	}

	out, err = os.CreateTemp(t.TempDir(), "stdout")
	if err != nil {
		t.Fatal(err)
	}

	if err := run(envelope("sha256:"+strings.Repeat("0", 64)), out); err != nil {
		t.Fatalf("a wrong digest is a rejected result, not an error: %v", err)
	}

	written, _ := os.ReadFile(out.Name())
	if !strings.Contains(string(written), "request_digest_mismatch") {
		t.Fatalf("a wrong digest was not rejected: %s", written)
	}
}
