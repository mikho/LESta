package identity

import (
	"fmt"
	"os"
	"path/filepath"
)

// stagingPath is a dotfile in SftpConfigDir itself: same filesystem as the
// eventual live file (required for an atomic os.Rename), and invisible to
// sshd's own *.conf glob Include (its name ends in .staging, not .conf).
func (c *IdentityCapability) stagingPath(resourceID string) string {
	return filepath.Join(c.cfg.SftpConfigDir, "."+resourceID+".conf.staging")
}

func (c *IdentityCapability) livePath(resourceID string) string {
	return filepath.Join(c.cfg.SftpConfigDir, resourceID+".conf")
}

func (c *IdentityCapability) authorizedKeysPath(username string) string {
	return filepath.Join(c.cfg.AuthorizedKeysDir, username)
}

// writeStaging renders content into resourceID's staging dotfile, returning
// its path for validateCandidate and activateLive to use.
func (c *IdentityCapability) writeStaging(resourceID string, content []byte) (string, error) {
	path := c.stagingPath(resourceID)

	if err := os.WriteFile(path, content, 0o644); err != nil {
		return "", fmt.Errorf("writing staged Match block for %s: %w", resourceID, err)
	}

	return path, nil
}

// discardStaging removes a staging dotfile after a rejected validation; the
// generation number that would have been spent is not, since activation was
// never reached for it.
func (c *IdentityCapability) discardStaging(resourceID string) {
	_ = os.Remove(c.stagingPath(resourceID))
}

// activateLive atomically renames the validated staging dotfile over the
// live Match-block fragment.
func (c *IdentityCapability) activateLive(resourceID string) error {
	if err := os.Rename(c.stagingPath(resourceID), c.livePath(resourceID)); err != nil {
		return fmt.Errorf("activating live Match block for %s: %w", resourceID, err)
	}

	return nil
}

// removeLive deletes the live Match-block fragment (delete's own
// "activation" step: there is no new content to rename in, only an absence
// to make real). Removing an already-absent fragment is not an error, so a
// rollback that re-removes it after a partial failure stays idempotent.
func (c *IdentityCapability) removeLive(resourceID string) error {
	err := os.Remove(c.livePath(resourceID))
	if err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("removing live Match block for %s: %w", resourceID, err)
	}

	return nil
}

// writeAuthorizedKeys atomically replaces username's own centralized
// authorized_keys file (Config.AuthorizedKeysDir/<username>): write to a
// same-directory temp file, then os.Rename over the real path, so a
// concurrent SFTP login attempt never observes a half-written file. content
// may be empty (renderAuthorizedKeys's own "no key on file" case) --  an
// empty file is written just the same, never skipped, so a key rotation
// that removes a key is real and immediate, not left stale.
//
// A package-level function, not a method, and always run as real root (see
// writeAuthorizedKeysPrivileged): sshd's own secure_path() safety check
// (confirmed directly against a real node via `sshd -d -d -d`, which logged
// "Authentication refused: bad ownership or modes") independently requires
// every AuthorizedKeysFile candidate, and every directory component up to
// it, be owned by root (or the connecting user) and have no group/other
// write bit at all -- so AuthorizedKeysDir itself (agent-daemon/install.sh)
// is root:root with no group access, meaning the real unprivileged
// lesta-agent-daemon could never write here directly even before
// considering file ownership. chown to root:root plus 0644 (world-readable,
// since the content is a public key, never secret, but not group/other
// writable) is what makes both of sshd's own checks pass at once: the
// earlier, narrower fix (0644 alone, owned by lesta-agent) satisfied only
// the privilege-drop-before-open mechanism, not this separate, stricter
// structural check.
func writeAuthorizedKeys(cfg Config, username string, content []byte) error {
	dest := filepath.Join(cfg.AuthorizedKeysDir, username)

	tmp, err := os.CreateTemp(cfg.AuthorizedKeysDir, "."+username+".authorized_keys.staging-*")
	if err != nil {
		return fmt.Errorf("creating staged authorized_keys file for %s: %w", username, err)
	}
	tmpPath := tmp.Name()

	if _, err := tmp.Write(content); err != nil {
		_ = tmp.Close()
		_ = os.Remove(tmpPath)

		return fmt.Errorf("writing staged authorized_keys file for %s: %w", username, err)
	}

	if err := tmp.Close(); err != nil {
		_ = os.Remove(tmpPath)

		return fmt.Errorf("closing staged authorized_keys file for %s: %w", username, err)
	}

	if err := os.Chown(tmpPath, 0, 0); err != nil {
		_ = os.Remove(tmpPath)

		return fmt.Errorf("setting ownership on staged authorized_keys file for %s: %w", username, err)
	}

	if err := os.Chmod(tmpPath, 0o644); err != nil {
		_ = os.Remove(tmpPath)

		return fmt.Errorf("setting permissions on staged authorized_keys file for %s: %w", username, err)
	}

	if err := os.Rename(tmpPath, dest); err != nil {
		_ = os.Remove(tmpPath)

		return fmt.Errorf("activating authorized_keys file for %s: %w", username, err)
	}

	return nil
}
