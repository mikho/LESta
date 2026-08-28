package bind9

import (
	"fmt"
	"os"
	"path/filepath"
)

// stagingPath is a dotfile in LiveDir itself: same filesystem as the eventual
// live file (required for an atomic os.Rename), and invisible to named's own
// *.conf glob include (its name ends in .staging, not .conf).
func (c *Bind9Capability) stagingPath(resourceID string) string {
	return filepath.Join(c.cfg.LiveDir, "."+resourceID+".conf.staging")
}

func (c *Bind9Capability) livePath(resourceID string) string {
	return filepath.Join(c.cfg.LiveDir, resourceID+".conf")
}

// writeStaging renders content into resourceID's staging dotfile, returning
// its path for validateCandidate and activateLive to use.
func (c *Bind9Capability) writeStaging(resourceID string, content []byte) (string, error) {
	path := c.stagingPath(resourceID)

	if err := os.WriteFile(path, content, 0o644); err != nil {
		return "", fmt.Errorf("writing staged fragment for %s: %w", resourceID, err)
	}

	return path, nil
}

// discardStaging removes a staging dotfile after a rejected validation; the
// generation number that would have been spent is not, since Activate was
// never reached for it.
func (c *Bind9Capability) discardStaging(resourceID string) {
	_ = os.Remove(c.stagingPath(resourceID))
}

// activateLive atomically renames the validated staging dotfile over the
// live fragment.
func (c *Bind9Capability) activateLive(resourceID string) error {
	if err := os.Rename(c.stagingPath(resourceID), c.livePath(resourceID)); err != nil {
		return fmt.Errorf("activating live fragment for %s: %w", resourceID, err)
	}

	return nil
}

// removeLive deletes the live fragment (delete's own "activation" step, and
// the suspended path's: there is no new content to rename in, only an
// absence to make real). Removing an already-absent fragment is not an
// error, so a rollback that re-removes it after a partial failure stays
// idempotent.
func (c *Bind9Capability) removeLive(resourceID string) error {
	err := os.Remove(c.livePath(resourceID))
	if err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("removing live fragment for %s: %w", resourceID, err)
	}

	return nil
}
