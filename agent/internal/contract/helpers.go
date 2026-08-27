// Package contract holds the shared table-driven Case list both fake.Capability
// and nginx.NginxCapability must satisfy, run against each via identical test
// code (see fake_test.go and nginx_test.go in their respective packages, which
// both call RunAgainst).
package contract

import (
	"crypto/rand"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// newUUID returns an RFC 4122 version-4-shaped UUID string. The envelope
// schema declares resource_id/idempotency_key/correlation_id as
// format:"uuid"; encoding/json does not enforce "format" itself, but building
// real-looking UUIDs keeps these tests honest about the shape production
// envelopes actually have.
func newUUID() string {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		panic(fmt.Sprintf("generating test UUID: %v", err))
	}

	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80

	return fmt.Sprintf("%s-%s-%s-%s-%s",
		hex.EncodeToString(b[0:4]),
		hex.EncodeToString(b[4:6]),
		hex.EncodeToString(b[6:8]),
		hex.EncodeToString(b[8:10]),
		hex.EncodeToString(b[10:16]),
	)
}

// domainFor derives a syntactically valid, collision-resistant test domain
// from t's own name plus a random suffix.
func domainFor(t *testing.T) string {
	t.Helper()

	return fmt.Sprintf("case-%s.contract.test", hex.EncodeToString(randomBytes(4)))
}

func randomBytes(n int) []byte {
	b := make([]byte, n)
	if _, err := rand.Read(b); err != nil {
		panic(fmt.Sprintf("generating random bytes: %v", err))
	}

	return b
}

// nginxPayload builds the web.nginx.v1 payload shape (WebDomain::toProvisioningPayload()'s
// prose shape), which every contract case sends regardless of which capability
// it runs against: FakeCapability ignores everything but domain/aliases,
// NginxCapability depends on every field.
func nginxPayload(domain string, suspended bool) json.RawMessage {
	raw, err := json.Marshal(map[string]any{
		"domain":       domain,
		"aliases":      []string{},
		"ip_address":   "127.0.0.1",
		"web_template": "default",
		"ssl":          map[string]any{"mode": "off"},
		"suspended":    suspended,
	})
	if err != nil {
		panic(fmt.Sprintf("marshaling nginx payload: %v", err))
	}

	return raw
}

// buildEnvelope constructs a well-formed OperationEnvelope. Every case gives
// its own operation a fresh idempotencyKey unless it explicitly wants to prove
// duplicate-delivery behavior (case_duplicate_idempotency_key.go is the only
// one that reuses a key on purpose): a real client is expected to mint one key
// per logically distinct request, never reuse one across different intended
// operations.
func buildEnvelope(operation protocol.Operation, resourceID, idempotencyKey string, desiredStateVersion int, domain string, suspended bool) protocol.OperationEnvelope {
	now := time.Now().UTC()

	return protocol.OperationEnvelope{
		ProtocolVersion:     "1",
		Capability:          "web.nginx.v1",
		Operation:           operation,
		ResourceID:          resourceID,
		DesiredStateVersion: desiredStateVersion,
		IdempotencyKey:      idempotencyKey,
		CorrelationID:       newUUID(),
		Deadline:            now.Add(10 * time.Second),
		IssuedAt:            now,
		RequestDigest:       "sha256:" + hex.EncodeToString(make([]byte, 32)),
		Payload:             nginxPayload(domain, suspended),
	}
}

// marshalWithTemplate returns raw with its web_template field replaced by
// template, for the unsupported-web-template contract case.
func marshalWithTemplate(raw json.RawMessage, template string) (json.RawMessage, error) {
	var m map[string]any
	if err := json.Unmarshal(raw, &m); err != nil {
		return nil, err
	}

	m["web_template"] = template

	return json.Marshal(m)
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

func requireApplied(t *testing.T, label string, result protocol.ResultEnvelope, err error) {
	t.Helper()

	requireStatus(t, label, result, err, protocol.StatusApplied)
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
