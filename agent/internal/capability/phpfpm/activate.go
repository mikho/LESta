package phpfpm

import (
	"fmt"
	"os"
	"path/filepath"
)

// stagingPath is a dotfile in this version's own poolDir itself (same
// filesystem as the eventual live file, required for an atomic
// os.Rename), invisible to php-fpm's own *.conf glob include (its name
// ends in .staging, not .conf).
func (c *PhpFpmCapability) stagingPath(version, resourceID string) string {
	return filepath.Join(c.cfg.poolDir(version), "."+resourceID+".conf.staging")
}

func (c *PhpFpmCapability) livePath(version, resourceID string) string {
	return filepath.Join(c.cfg.poolDir(version), resourceID+".conf")
}

// writeStaging renders content into resourceID's staging dotfile under
// version's own poolDir, returning its path for validateCandidate and
// activateLive to use.
func (c *PhpFpmCapability) writeStaging(version, resourceID string, content []byte) (string, error) {
	path := c.stagingPath(version, resourceID)

	if err := os.WriteFile(path, content, 0o644); err != nil {
		return "", fmt.Errorf("writing staged pool fragment for %s: %w", resourceID, err)
	}

	return path, nil
}

// discardStaging removes a staging dotfile after a rejected validation; the
// generation number that would have been spent is not, since Activate was
// never reached for it.
func (c *PhpFpmCapability) discardStaging(version, resourceID string) {
	_ = os.Remove(c.stagingPath(version, resourceID))
}

// activateLive atomically renames the validated staging dotfile over the
// live pool fragment.
func (c *PhpFpmCapability) activateLive(version, resourceID string) error {
	if err := os.Rename(c.stagingPath(version, resourceID), c.livePath(version, resourceID)); err != nil {
		return fmt.Errorf("activating live pool fragment for %s: %w", resourceID, err)
	}

	return nil
}

// removeLive deletes the live pool fragment under version's own poolDir.
// Removing an already-absent fragment is not an error, so a rollback that
// re-removes it after a partial failure stays idempotent.
func (c *PhpFpmCapability) removeLive(version, resourceID string) error {
	err := os.Remove(c.livePath(version, resourceID))
	if err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("removing live pool fragment for %s: %w", resourceID, err)
	}

	return nil
}
