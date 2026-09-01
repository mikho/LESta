package acme_test

import (
	"crypto/rand"
	"encoding/json"
	"fmt"
	"strings"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// Unlike nginx/apache/bind9's own harness_test.go, this file spins up no
// disposable external process at all: this capability never runs nginx,
// apache2, or named, so there is no "requireReal..." skip guard here either
// (see capability.go's own package doc comment for why -- it is pure
// filesystem logic). This file only holds the small envelope-construction
// helpers acme_test.go needs, mirroring the other three packages' own
// harness_test.go/newOp shape so a reader already familiar with those files
// recognizes this one immediately.

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
		Capability:          "tls.acme.v1",
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

func http01Payload(token, keyAuthorization string) map[string]any {
	return map[string]any{
		"kind":              "http01_challenge",
		"token":             token,
		"key_authorization": keyAuthorization,
	}
}

func certificatePayload(domain, fullChainPEM, privateKeyPEM string) map[string]any {
	return map[string]any{
		"kind":            "certificate",
		"domain":          domain,
		"full_chain_pem":  fullChainPEM,
		"private_key_pem": privateKeyPEM,
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
