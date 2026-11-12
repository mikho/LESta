package identity

import (
	"fmt"
	"os"
	"path/filepath"
)

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

// removeAuthorizedKeys deletes username's centralized authorized_keys file, once the account has no
// user on this node any more. An already-absent file is not an error.
func removeAuthorizedKeys(cfg Config, username string) error {
	err := os.Remove(filepath.Join(cfg.AuthorizedKeysDir, username))
	if err != nil && !os.IsNotExist(err) {
		return fmt.Errorf("removing authorized_keys for %s: %w", username, err)
	}

	return nil
}
