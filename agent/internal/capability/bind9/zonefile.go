package bind9

import (
	"fmt"
	"os"
	"path/filepath"
)

// zoneDataPath is the absolute path to resourceID's generation n zone data
// file, the path a rendered stanza's `file` directive points at.
func (c *Bind9Capability) zoneDataPath(resourceID string, n int) string {
	return filepath.Join(c.store.GenerationDir(resourceID, n), "zone.db")
}

// writeZoneData writes content to resourceID's generation n zone data file,
// creating the generation directory if it doesn't exist yet: this is called
// before generation.Store's own Activate (which would otherwise create it),
// since the candidate stanza's `file` directive must reference a real,
// already-readable path for named-checkconf -z to validate its content.
func (c *Bind9Capability) writeZoneData(resourceID string, n int, content []byte) error {
	dir := c.store.GenerationDir(resourceID, n)
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return fmt.Errorf("creating generation directory for %s generation %d: %w", resourceID, n, err)
	}

	if err := os.WriteFile(c.zoneDataPath(resourceID, n), content, 0o644); err != nil {
		return fmt.Errorf("writing zone data for %s generation %d: %w", resourceID, n, err)
	}

	return nil
}

// discardZoneData removes a generation's zone data file after a rejected
// validation attempt. Disk hygiene only, not load-bearing correctness: an
// orphaned zone.db under a generation directory that never got Activated is
// harmless (nothing ever references it), this just keeps the state tree tidy
// for operator forensics, mirroring nginx's discardStaging symmetry.
func (c *Bind9Capability) discardZoneData(resourceID string, n int) {
	_ = os.Remove(c.zoneDataPath(resourceID, n))
}
