package identity

import (
	"fmt"
	"os"
	"os/user"
	"path/filepath"
	"strconv"
)

// ensureChrootTree creates username's own chroot root
// (Config.AccountsRoot/username), root-owned mode 0755 -- OpenSSH's
// ChrootDirectory has a hard, non-negotiable requirement that every path
// component up to and including the chroot root itself be owned by root and
// not group/world-writable, checked by sshd at connection time regardless of
// StrictModes -- with a public/ subdirectory beneath it owned by username
// itself, mode 0750, where this account's own SFTP session and a future
// web.php-fpm.v1 pool both read/write. Idempotent: safe to call on every
// applyCreate, including a re-run against an account that already has one.
func ensureChrootTree(cfg Config, username string) error {
	u, err := user.Lookup(username)
	if err != nil {
		return fmt.Errorf("looking up uid/gid for %s: %w", username, err)
	}

	uid, err := strconv.Atoi(u.Uid)
	if err != nil {
		return fmt.Errorf("parsing uid %q for %s: %w", u.Uid, username, err)
	}

	gid, err := strconv.Atoi(u.Gid)
	if err != nil {
		return fmt.Errorf("parsing gid %q for %s: %w", u.Gid, username, err)
	}

	root := chrootRoot(cfg, username)

	if err := os.MkdirAll(root, 0o755); err != nil {
		return fmt.Errorf("creating chroot root %s: %w", root, err)
	}

	// Explicit chmod after MkdirAll: MkdirAll's own mode is subject to the
	// process umask, which could otherwise leave this narrower than the real
	// 0755 ChrootDirectory requires.
	if err := os.Chmod(root, 0o755); err != nil {
		return fmt.Errorf("setting permissions on chroot root %s: %w", root, err)
	}

	// Chown to root:root explicitly rather than trusting the agent daemon's
	// own real-world run-as-root identity to already be root:root by
	// default: explicit is correct regardless of which account created the
	// directory first.
	if err := os.Chown(root, 0, 0); err != nil {
		return fmt.Errorf("setting ownership on chroot root %s: %w", root, err)
	}

	publicDir := filepath.Join(root, "public")

	if err := os.MkdirAll(publicDir, 0o750); err != nil {
		return fmt.Errorf("creating public directory %s: %w", publicDir, err)
	}

	if err := os.Chmod(publicDir, 0o750); err != nil {
		return fmt.Errorf("setting permissions on public directory %s: %w", publicDir, err)
	}

	if err := os.Chown(publicDir, uid, gid); err != nil {
		return fmt.Errorf("setting ownership on public directory %s: %w", publicDir, err)
	}

	return nil
}

func chrootRoot(cfg Config, username string) string {
	return filepath.Join(cfg.AccountsRoot, username)
}
