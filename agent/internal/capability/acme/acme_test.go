package acme_test

import (
	"context"
	"os"
	"path/filepath"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/acme"
	"github.com/mikho/LESta/agent/internal/protocol"
)

const (
	fakeFullChainPEM  = "-----BEGIN CERTIFICATE-----\nZmFrZS1jZXJ0\n-----END CERTIFICATE-----\n"
	fakePrivateKeyPEM = "-----BEGIN PRIVATE KEY-----\nZmFrZS1rZXk=\n-----END PRIVATE KEY-----\n"
)

func newCapability(t *testing.T) (*acme.AcmeCapability, string) {
	t.Helper()

	stateRoot := t.TempDir()

	return acme.New(acme.Config{StateRoot: stateRoot}), stateRoot
}

func TestHTTP01ChallengeCreateWritesFileThenDeleteRemovesIt(t *testing.T) {
	capability, stateRoot := newCapability(t)
	ctx := context.Background()

	resourceID := newTestUUID()
	token := "an-example-token_with-url-safe-chars"
	keyAuth := token + ".thumbprint-placeholder"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, http01Payload(token, keyAuth)))
	requireApplied(t, "create", created, err)

	challengePath := filepath.Join(stateRoot, "http-01", token)

	content, err := os.ReadFile(challengePath)
	if err != nil {
		t.Fatalf("expected the challenge file to exist at %s: %v", challengePath, err)
	}
	if string(content) != keyAuth {
		t.Fatalf("expected challenge file content %q, got %q", keyAuth, string(content))
	}

	// The atomic write-then-rename helper must never leave its ".tmp"
	// sibling behind after a successful write.
	if _, err := os.Stat(challengePath + ".tmp"); !os.IsNotExist(err) {
		t.Fatalf("expected no leftover .tmp file after a successful atomic write, stat err=%v", err)
	}

	deleted, err := capability.Apply(ctx, newOp(protocol.OperationDelete, resourceID, newTestUUID(), 2, http01Payload(token, keyAuth)))
	requireApplied(t, "delete", deleted, err)

	if _, err := os.Stat(challengePath); !os.IsNotExist(err) {
		t.Fatalf("expected the challenge file to be removed after delete, stat err=%v", err)
	}

	// Deleting an already-absent challenge file is idempotent, not an
	// error: a retried delete (or a delete for a create that never landed)
	// must still report applied.
	deletedAgain, err := capability.Apply(ctx, newOp(protocol.OperationDelete, resourceID, newTestUUID(), 3, http01Payload(token, keyAuth)))
	requireApplied(t, "delete again", deletedAgain, err)
}

func TestHTTP01ChallengeDuplicateIdempotencyKeyReturnsAlreadyAppliedWithoutRewriting(t *testing.T) {
	capability, stateRoot := newCapability(t)
	ctx := context.Background()

	resourceID := newTestUUID()
	idempotencyKey := newTestUUID()
	token := "dup-token"
	op := newOp(protocol.OperationCreate, resourceID, idempotencyKey, 1, http01Payload(token, token+".thumb"))

	first, err := capability.Apply(ctx, op)
	requireApplied(t, "first create", first, err)

	second, err := capability.Apply(ctx, op)
	requireStatus(t, "duplicate create", second, err, protocol.StatusAlreadyApplied)

	if second.GenerationID != first.GenerationID {
		t.Fatalf("expected the duplicate to echo the original generation_id %q, got %q (implies re-writing happened)", first.GenerationID, second.GenerationID)
	}
	if second.ObservedStateDigest != first.ObservedStateDigest {
		t.Fatalf("expected the duplicate to echo the original digest %q, got %q", first.ObservedStateDigest, second.ObservedStateDigest)
	}

	challengePath := filepath.Join(stateRoot, "http-01", token)
	if _, err := os.Stat(challengePath); err != nil {
		t.Fatalf("expected the challenge file to still exist: %v", err)
	}
}

func TestCertificateCreateWritesFullChainAndPrivateKeyAtomicallyWithStrictPrivateKeyMode(t *testing.T) {
	capability, stateRoot := newCapability(t)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "cert-create.acme.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, certificatePayload(domain, fakeFullChainPEM, fakePrivateKeyPEM)))
	requireApplied(t, "create", created, err)

	fullChainPath := filepath.Join(stateRoot, "certs", domain, "fullchain.pem")
	privateKeyPath := filepath.Join(stateRoot, "certs", domain, "privkey.pem")

	fullChain, err := os.ReadFile(fullChainPath)
	if err != nil {
		t.Fatalf("expected fullchain.pem to exist: %v", err)
	}
	if string(fullChain) != fakeFullChainPEM {
		t.Fatalf("expected fullchain.pem content %q, got %q", fakeFullChainPEM, string(fullChain))
	}

	privateKeyInfo, err := os.Stat(privateKeyPath)
	if err != nil {
		t.Fatalf("expected privkey.pem to exist: %v", err)
	}
	if privateKeyInfo.Mode().Perm() != 0o600 {
		t.Fatalf("expected privkey.pem to be mode 0600, got %o", privateKeyInfo.Mode().Perm())
	}

	privateKey, err := os.ReadFile(privateKeyPath)
	if err != nil {
		t.Fatalf("reading privkey.pem: %v", err)
	}
	if string(privateKey) != fakePrivateKeyPEM {
		t.Fatalf("expected privkey.pem content %q, got %q", fakePrivateKeyPEM, string(privateKey))
	}
}

func TestCertificateUpdateOverwritesExistingFiles(t *testing.T) {
	capability, stateRoot := newCapability(t)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "cert-update.acme.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, certificatePayload(domain, fakeFullChainPEM, fakePrivateKeyPEM)))
	requireApplied(t, "create", created, err)

	newFullChain := "-----BEGIN CERTIFICATE-----\ndXBkYXRlZC1jZXJ0\n-----END CERTIFICATE-----\n"
	newPrivateKey := "-----BEGIN PRIVATE KEY-----\ndXBkYXRlZC1rZXk=\n-----END PRIVATE KEY-----\n"

	updated, err := capability.Apply(ctx, newOp(protocol.OperationUpdate, resourceID, newTestUUID(), 2, certificatePayload(domain, newFullChain, newPrivateKey)))
	requireApplied(t, "update", updated, err)

	if updated.GenerationID == created.GenerationID {
		t.Fatalf("expected update to land in a new generation, got the same generation_id %q as create", created.GenerationID)
	}

	fullChain, err := os.ReadFile(filepath.Join(stateRoot, "certs", domain, "fullchain.pem"))
	if err != nil {
		t.Fatalf("reading updated fullchain.pem: %v", err)
	}
	if string(fullChain) != newFullChain {
		t.Fatalf("expected the updated fullchain.pem content %q, got %q", newFullChain, string(fullChain))
	}
}

func TestDeleteIsUnsupportedForCertificateKind(t *testing.T) {
	capability, _ := newCapability(t)
	ctx := context.Background()

	resourceID := newTestUUID()
	domain := "cert-delete.acme.test"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, certificatePayload(domain, fakeFullChainPEM, fakePrivateKeyPEM)))
	requireApplied(t, "create", created, err)

	result, err := capability.Apply(ctx, newOp(protocol.OperationDelete, resourceID, newTestUUID(), 2, certificatePayload(domain, fakeFullChainPEM, fakePrivateKeyPEM)))
	requireStatus(t, "delete on certificate kind", result, err, protocol.StatusRejected)
	requireErrorCode(t, "delete on certificate kind", result, "unsupported_operation")
}

// TestSuspendUnsuspendAreUnsupportedOperations proves ACME resources have no
// suspended-state concept this phase: every operation other than
// create/update/delete/observe is rejected outright.
func TestSuspendUnsuspendAreUnsupportedOperations(t *testing.T) {
	capability, _ := newCapability(t)
	ctx := context.Background()

	for _, op := range []protocol.Operation{protocol.OperationSuspend, protocol.OperationUnsuspend} {
		t.Run(string(op), func(t *testing.T) {
			resourceID := newTestUUID()
			result, err := capability.Apply(ctx, newOp(op, resourceID, newTestUUID(), 1, http01Payload("some-token", "some-token.thumb")))
			requireStatus(t, string(op), result, err, protocol.StatusRejected)
			requireErrorCode(t, string(op), result, "unsupported_operation")
		})
	}
}

func TestInvalidPayloadRejections(t *testing.T) {
	capability, _ := newCapability(t)
	ctx := context.Background()

	t.Run("unknown kind", func(t *testing.T) {
		payload := http01Payload("tok", "tok.thumb")
		payload["kind"] = "bogus"
		result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, payload))
		requireStatus(t, "unknown kind", result, err, protocol.StatusRejected)
		requireErrorCode(t, "unknown kind", result, "invalid_kind")
	})

	t.Run("missing token", func(t *testing.T) {
		result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, http01Payload("", "some.thumb")))
		requireStatus(t, "missing token", result, err, protocol.StatusRejected)
		requireErrorCode(t, "missing token", result, "invalid_token")
	})

	t.Run("token with path traversal characters", func(t *testing.T) {
		result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, http01Payload("../../etc/passwd", "x.thumb")))
		requireStatus(t, "path traversal token", result, err, protocol.StatusRejected)
		requireErrorCode(t, "path traversal token", result, "invalid_token")
	})

	t.Run("missing key authorization", func(t *testing.T) {
		result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, http01Payload("tok", "")))
		requireStatus(t, "missing key authorization", result, err, protocol.StatusRejected)
		requireErrorCode(t, "missing key authorization", result, "invalid_key_authorization")
	})

	t.Run("missing domain", func(t *testing.T) {
		result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, certificatePayload("", fakeFullChainPEM, fakePrivateKeyPEM)))
		requireStatus(t, "missing domain", result, err, protocol.StatusRejected)
		requireErrorCode(t, "missing domain", result, "invalid_domain")
	})

	t.Run("malformed full chain", func(t *testing.T) {
		result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, certificatePayload("bad-cert.acme.test", "not a pem", fakePrivateKeyPEM)))
		requireStatus(t, "malformed full chain", result, err, protocol.StatusRejected)
		requireErrorCode(t, "malformed full chain", result, "invalid_certificate")
	})

	t.Run("malformed private key", func(t *testing.T) {
		result, err := capability.Apply(ctx, newOp(protocol.OperationCreate, newTestUUID(), newTestUUID(), 1, certificatePayload("bad-key.acme.test", fakeFullChainPEM, "not a pem")))
		requireStatus(t, "malformed private key", result, err, protocol.StatusRejected)
		requireErrorCode(t, "malformed private key", result, "invalid_certificate")
	})
}

func TestObserveBeforeAnyCreateIsUnknownResource(t *testing.T) {
	capability, _ := newCapability(t)
	ctx := context.Background()

	resourceID := newTestUUID()
	result, err := capability.Apply(ctx, newOp(protocol.OperationObserve, resourceID, newTestUUID(), 1, http01Payload("tok", "tok.thumb")))
	requireStatus(t, "observe before create", result, err, protocol.StatusRejected)
	requireErrorCode(t, "observe before create", result, "unknown_resource")
}

func TestObserveAfterCreateReportsAppliedThenDegradedAfterManualDrift(t *testing.T) {
	capability, stateRoot := newCapability(t)
	ctx := context.Background()

	resourceID := newTestUUID()
	token := "observe-token"

	created, err := capability.Apply(ctx, newOp(protocol.OperationCreate, resourceID, newTestUUID(), 1, http01Payload(token, token+".thumb")))
	requireApplied(t, "create", created, err)

	observed, err := capability.Apply(ctx, newOp(protocol.OperationObserve, resourceID, newTestUUID(), 1, http01Payload(token, token+".thumb")))
	requireApplied(t, "observe", observed, err)

	if observed.ObservedStateDigest != created.ObservedStateDigest {
		t.Fatalf("expected observe to report the same digest create just established: create=%s observe=%s", created.ObservedStateDigest, observed.ObservedStateDigest)
	}

	// Simulate drift: something outside this capability's own Apply pipeline
	// modified the live file on disk.
	challengePath := filepath.Join(stateRoot, "http-01", token)
	if err := os.WriteFile(challengePath, []byte("tampered content"), 0o644); err != nil {
		t.Fatalf("simulating drift: %v", err)
	}

	drifted, err := capability.Apply(ctx, newOp(protocol.OperationObserve, resourceID, newTestUUID(), 1, http01Payload(token, token+".thumb")))
	requireStatus(t, "observe after drift", drifted, err, protocol.StatusDegraded)
	requireErrorCode(t, "observe after drift", drifted, "drift_detected")
}
