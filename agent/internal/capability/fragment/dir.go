// Package fragment is what the capabilities that keep one config fragment per resource in a
// directory the service glob-includes share: nginx, apache, bind9, php-fpm and the sshd Match
// blocks of identity. It covers the staging dotfile and its atomic activation (Dir), the synthetic
// config a candidate is validated against without touching the live one (SyntheticConfig), the
// reload-then-probe loop (Reload, PollUntil, WaitDial) and the result envelopes (Results). How a
// fragment is rendered, and what a rollback re-stages, stays with each capability.
package fragment

import (
	"fmt"
	"os"
	"path/filepath"
)

// Dir is the live directory the service includes *.conf fragments from.
type Dir struct {
	Path string

	// Noun is what one fragment is called in errors: "fragment", "Match block", "pool fragment".
	Noun string
}

// StagingPath is a dotfile in Path itself: same filesystem as the eventual live file (required
// for an atomic os.Rename), and invisible to the service's own *.conf glob include (its name ends
// in .staging, not .conf).
func (d Dir) StagingPath(resourceID string) string {
	return filepath.Join(d.Path, "."+resourceID+".conf.staging")
}

// LivePath is the fragment the service includes.
func (d Dir) LivePath(resourceID string) string {
	return filepath.Join(d.Path, resourceID+".conf")
}

// WriteStaging writes content into resourceID's staging dotfile, returning its path for the
// validation and ActivateLive to use.
func (d Dir) WriteStaging(resourceID string, content []byte) (string, error) {
	path := d.StagingPath(resourceID)

	if err := os.WriteFile(path, content, 0o644); err != nil {
		return "", fmt.Errorf("writing staged %s for %s: %w", d.Noun, resourceID, err)
	}

	return path, nil
}

// DiscardStaging removes a staging dotfile after a rejected validation; the generation number
// that would have been spent is not, since activation was never reached for it.
func (d Dir) DiscardStaging(resourceID string) {
	_ = os.Remove(d.StagingPath(resourceID))
}

// ActivateLive atomically renames the validated staging dotfile over the live fragment.
func (d Dir) ActivateLive(resourceID string) error {
	if err := os.Rename(d.StagingPath(resourceID), d.LivePath(resourceID)); err != nil {
		return fmt.Errorf("activating live %s for %s: %w", d.Noun, resourceID, err)
	}

	return nil
}

// RemoveLive deletes the live fragment (delete's own "activation" step: there is no new content
// to rename in, only an absence to make real). Removing an already-absent fragment is not an
// error, so a rollback that re-removes it after a partial failure stays idempotent.
func (d Dir) RemoveLive(resourceID string) error {
	err := os.Remove(d.LivePath(resourceID))
	if err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("removing live %s for %s: %w", d.Noun, resourceID, err)
	}

	return nil
}
