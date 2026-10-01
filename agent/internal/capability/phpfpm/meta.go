package phpfpm

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
)

// generationMetaFileName holds the exact structured Payload a generation
// was rendered from, alongside the generic content/manifest files
// internal/generation.Store itself writes. Only ever read for a rollback's
// own reload/health-check step (it needs to know which version's own
// systemd unit and socket to check), never as a source of truth for the
// pool content itself.
const generationMetaFileName = "payload.json"

func (c *PhpFpmCapability) generationMetaPath(resourceID string, n int) string {
	return filepath.Join(c.store.GenerationDir(resourceID, n), generationMetaFileName)
}

func (c *PhpFpmCapability) writeGenerationMeta(resourceID string, n int, payload Payload) error {
	raw, err := json.Marshal(payload)
	if err != nil {
		return fmt.Errorf("encoding generation metadata for %s generation %d: %w", resourceID, n, err)
	}

	if err := os.WriteFile(c.generationMetaPath(resourceID, n), raw, 0o644); err != nil {
		return fmt.Errorf("writing generation metadata for %s generation %d: %w", resourceID, n, err)
	}

	return nil
}

func (c *PhpFpmCapability) readGenerationMeta(resourceID string, n int) (Payload, error) {
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
