// Package idempotency provides a service-agnostic receipt store: given an
// idempotency key, remember the ResultEnvelope produced the first time it was
// seen, so a duplicate delivery of the same request can be answered without
// redoing the underlying work.
//
// Store is in memory: each capability keeps one for the operations it sees
// during the life of its process. Dir (dir.go) keeps receipts on disk, so the
// applier can answer a redelivered operation after it restarted.
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
