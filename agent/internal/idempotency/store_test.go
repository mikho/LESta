package idempotency_test

import (
	"testing"

	"github.com/mikho/LESta/agent/internal/idempotency"
	"github.com/mikho/LESta/agent/internal/protocol"
)

func TestStoreLookupMiss(t *testing.T) {
	store := idempotency.New()

	if _, ok := store.Lookup("unseen-key"); ok {
		t.Fatal("expected no receipt for an unseen idempotency key")
	}
}

func TestStoreRecordThenLookup(t *testing.T) {
	store := idempotency.New()

	want := protocol.ResultEnvelope{
		IdempotencyKey: "key-1",
		Status:         protocol.StatusApplied,
		GenerationID:   "1",
		Errors:         []protocol.ResultError{},
	}

	store.Record("key-1", want)

	got, ok := store.Lookup("key-1")
	if !ok {
		t.Fatal("expected a receipt for a recorded idempotency key")
	}
	if got.GenerationID != want.GenerationID || got.Status != want.Status {
		t.Fatalf("receipt mismatch: got %+v, want %+v", got, want)
	}
}
