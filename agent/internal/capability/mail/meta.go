package mail

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
)

// generationMetaFileName holds the exact structured Payload a generation was
// rendered from (password-redacted, see payload.go's own marshalMeta),
// mirroring bind9's identical precedent. Read by listKnownDomains to
// reconstruct every OTHER active domain's current state when rebuilding this
// node's aggregate Exim/Dovecot data files (see render.go), since this
// capability has no per-resource glob-included fragment the way bind9 does
// -- Exim's own lookup data must be rebuilt in full from every sibling
// resource's own last-known-good state on every apply.
const generationMetaFileName = "payload.json"

func (c *MailCapability) domainsRoot() string {
	return filepath.Join(c.cfg.StateRoot, "domains")
}

func (c *MailCapability) generationMetaPath(resourceID string, n int) string {
	return filepath.Join(c.store.GenerationDir(resourceID, n), generationMetaFileName)
}

func (c *MailCapability) writeGenerationMeta(resourceID string, n int, payload Payload) error {
	raw, err := payload.marshalMeta()
	if err != nil {
		return err
	}

	if err := os.WriteFile(c.generationMetaPath(resourceID, n), raw, 0o644); err != nil {
		return fmt.Errorf("writing generation metadata for %s generation %d: %w", resourceID, n, err)
	}

	return nil
}

func (c *MailCapability) readGenerationMeta(resourceID string, n int) (Payload, error) {
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

// listKnownDomains scans this capability's own domainsRoot (a plain
// directory of resource_id subdirectories, one per MailDomain this node has
// ever been asked to provision -- generation.Store's own stable, documented
// layout) and returns the current, non-deleted Payload for every one of
// them except excludeResourceID (the resource this apply is already handling
// with its own, possibly-not-yet-persisted new payload). This is a local,
// package-scoped directory listing, not an addition to the shared
// generation.Store type: that package deliberately exposes no "list every
// resource" method (every other capability in this module needs only keyed
// access to one resource at a time), so enumerating siblings here, from
// within this package alone, keeps that shared abstraction unchanged.
func (c *MailCapability) listKnownDomains(excludeResourceID string) ([]Payload, error) {
	entries, err := os.ReadDir(c.domainsRoot())
	if err != nil {
		if os.IsNotExist(err) {
			return nil, nil
		}

		return nil, fmt.Errorf("listing known mail domains: %w", err)
	}

	var payloads []Payload

	for _, entry := range entries {
		if !entry.IsDir() || entry.Name() == excludeResourceID {
			continue
		}

		resourceID := entry.Name()

		n, ok, err := c.store.CurrentGeneration(resourceID)
		if err != nil {
			return nil, fmt.Errorf("reading current generation for %s: %w", resourceID, err)
		}
		if !ok {
			continue
		}

		_, deleted, err := c.store.ReadContent(resourceID, n)
		if err != nil {
			return nil, fmt.Errorf("reading generation content for %s generation %d: %w", resourceID, n, err)
		}
		if deleted {
			continue
		}

		payload, err := c.readGenerationMeta(resourceID, n)
		if err != nil {
			return nil, err
		}

		payloads = append(payloads, payload)
	}

	return payloads, nil
}
