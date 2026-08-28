package bind9

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
)

// generationMetaFileName holds the exact structured Payload a generation was
// rendered from, alongside the generic content/manifest files
// internal/generation.Store itself writes. That package stays fully
// service-agnostic (it only ever sees opaque content bytes and a digest);
// this sidecar is how the bind9 capability can later recover *which*
// domain/records/suspended state a stored generation represents, without
// needing to scrape it back out of rendered stanza text. It is read by
// recoverFromFailure to re-render a fresh zone.db and stanza for a rolled-back
// generation, never as a source of truth for the content itself in the
// ordinary (non-rollback) path.
const generationMetaFileName = "payload.json"

func (c *Bind9Capability) generationMetaPath(resourceID string, n int) string {
	return filepath.Join(c.store.GenerationDir(resourceID, n), generationMetaFileName)
}

func (c *Bind9Capability) writeGenerationMeta(resourceID string, n int, payload Payload) error {
	raw, err := json.Marshal(payload)
	if err != nil {
		return fmt.Errorf("encoding generation metadata for %s generation %d: %w", resourceID, n, err)
	}

	if err := os.WriteFile(c.generationMetaPath(resourceID, n), raw, 0o644); err != nil {
		return fmt.Errorf("writing generation metadata for %s generation %d: %w", resourceID, n, err)
	}

	return nil
}

func (c *Bind9Capability) readGenerationMeta(resourceID string, n int) (Payload, error) {
	raw, err := os.ReadFile(c.generationMetaPath(resourceID, n))
	if err != nil {
		return Payload{}, fmt.Errorf("reading generation metadata for %s generation %d: %w", resourceID, n, err)
	}

	var payload Payload
	if err := json.Unmarshal(raw, &payload); err != nil {
		return Payload{}, fmt.Errorf("parsing generation metadata for %s generation %d: %w", resourceID, n, err)
	}

	return payload, nil
}
