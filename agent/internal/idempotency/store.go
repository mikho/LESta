// Package idempotency provides a service-agnostic receipt store: given an
// idempotency key, remember the ResultEnvelope produced the first time it was
// seen, so a duplicate delivery of the same request can be answered without
// redoing the underlying work.
//
// The store is in-memory only, deliberately. The agent itself is a one-shot
// stdin/stdout process (see cmd/lesta-agent), not a daemon, so a disk-backed
// store would not actually help production idempotency across separate process
// invocations without the network transport and retry semantics that are out of
// scope for this phase; it would only add complexity the phase doesn't need. In
// exchange, an in-memory store is exactly what the shared contract suite needs:
// within a single test (or a single real agent invocation that happens to see
// the same envelope twice), a duplicate idempotency key is recognized and
// answered from the receipt, never re-rendered.
package idempotency

import (
	"sync"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// Store is safe for concurrent use.
type Store struct {
	mu       sync.Mutex
	receipts map[string]protocol.ResultEnvelope
}

// New returns an empty receipt store.
func New() *Store {
	return &Store{receipts: make(map[string]protocol.ResultEnvelope)}
}

// Lookup returns the receipt recorded for idempotencyKey, if any.
func (s *Store) Lookup(idempotencyKey string) (protocol.ResultEnvelope, bool) {
	s.mu.Lock()
	defer s.mu.Unlock()

	result, ok := s.receipts[idempotencyKey]

	return result, ok
}

// Record stores result as the receipt for idempotencyKey, overwriting any prior
// receipt for the same key (callers only record once per key in practice, since
// Lookup is always checked first).
func (s *Store) Record(idempotencyKey string, result protocol.ResultEnvelope) {
	s.mu.Lock()
	defer s.mu.Unlock()

	s.receipts[idempotencyKey] = result
}
