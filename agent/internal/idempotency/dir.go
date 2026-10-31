package idempotency

import (
	"encoding/json"
	"os"
	"path/filepath"
	"regexp"
	"sync"
	"time"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// keyPattern is the only idempotency key a Dir keeps a file for: a lower-case
// UUID, which is what the panel and every self-test send. Anything else is
// simply not remembered on disk, never turned into a path.
var keyPattern = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)

// pruneInterval is how often Record looks for receipts older than maxAge.
const pruneInterval = time.Hour

// Dir is a receipt store kept on disk, one file per key, so receipts survive
// a restart of the process that applies operations: an operation redelivered
// after its result was lost is answered from its receipt instead of failing as
// a create of something that already exists. Receipts older than maxAge are
// removed. Safe for concurrent use.
type Dir struct {
	path   string
	maxAge time.Duration

	mu        sync.Mutex
	lastPrune time.Time
}

// NewDir returns a store keeping its receipts in path, created 0700 on the
// first Record.
func NewDir(path string, maxAge time.Duration) *Dir {
	return &Dir{path: path, maxAge: maxAge}
}

// Lookup returns the receipt recorded for idempotencyKey, if there is one.
func (d *Dir) Lookup(idempotencyKey string) (protocol.ResultEnvelope, bool) {
	if !keyPattern.MatchString(idempotencyKey) {
		return protocol.ResultEnvelope{}, false
	}

	raw, err := os.ReadFile(filepath.Join(d.path, idempotencyKey+".json"))
	if err != nil {
		return protocol.ResultEnvelope{}, false
	}

	var result protocol.ResultEnvelope
	if err := json.Unmarshal(raw, &result); err != nil {
		return protocol.ResultEnvelope{}, false
	}

	return result, true
}

// Record keeps result as idempotencyKey's receipt.
func (d *Dir) Record(idempotencyKey string, result protocol.ResultEnvelope) error {
	if !keyPattern.MatchString(idempotencyKey) {
		return nil
	}

	raw, err := json.Marshal(result)
	if err != nil {
		return err
	}

	if err := os.MkdirAll(d.path, 0o700); err != nil {
		return err
	}

	path := filepath.Join(d.path, idempotencyKey+".json")
	if err := os.WriteFile(path+".tmp", raw, 0o600); err != nil {
		return err
	}

	if err := os.Rename(path+".tmp", path); err != nil {
		return err
	}

	d.pruneIfDue()

	return nil
}

// pruneIfDue removes receipts older than maxAge, at most once per
// pruneInterval.
func (d *Dir) pruneIfDue() {
	d.mu.Lock()
	if time.Since(d.lastPrune) < pruneInterval {
		d.mu.Unlock()

		return
	}
	d.lastPrune = time.Now()
	d.mu.Unlock()

	entries, err := os.ReadDir(d.path)
	if err != nil {
		return
	}

	cutoff := time.Now().Add(-d.maxAge)
	for _, entry := range entries {
		if info, err := entry.Info(); err == nil && info.ModTime().Before(cutoff) {
			_ = os.Remove(filepath.Join(d.path, entry.Name()))
		}
	}
}
