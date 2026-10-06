package nginx

import (
	"bytes"
	"fmt"
	"os"
	"os/exec"
	"os/user"
	"path/filepath"
	"regexp"
	"strconv"
)

// docrootResourceIDPattern mirrors cron's own resourceIDPattern exactly: a
// strict UUID shape, re-validated here from scratch since a value reaching
// ensureDocrootPrivileged crosses a real process boundary (sudo, running as
// root), not just an in-process call.
var docrootResourceIDPattern = regexp.MustCompile(`^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$`)

// docrootUsernamePattern mirrors identity/payload.go's own usernamePattern
// exactly, re-validated here for the same reason.
var docrootUsernamePattern = regexp.MustCompile(`^[a-z][a-z0-9_-]{0,31}$`)

// ensureDocroot creates resourceID's own per-domain docroot directory
// (Config.AccountsRoot/username/domains/resourceID/public) if it does not
// already exist, owned by username's own uid/gid, mode 0755 -- unlike
// identity's own "public" directory (0750, group-readable only, built for
// a single flat account-wide webroot predating this capability), this one
// is deliberately world-readable: it is a real web document root nginx
// itself serves to anyone who asks over HTTP, so restricting read access
// beyond that boundary buys no real confidentiality, while world-readable
// (never world-writable) still keeps content changes themselves
// exclusively up to this account's own SFTP session. Idempotent: safe to
// call on every create/update, including a re-run against a domain that
// already has one.
func ensureDocroot(cfg Config, username, resourceID string) error {
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

	docroot := filepath.Join(cfg.AccountsRoot, username, "domains", resourceID, "public")

	if err := os.MkdirAll(docroot, 0o755); err != nil {
		return fmt.Errorf("creating docroot %s: %w", docroot, err)
	}

	// Explicit chmod after MkdirAll: MkdirAll's own mode is subject to the
	// process umask, which could otherwise leave this narrower than the
	// real 0755 this directory needs to stay world-readable.
	if err := os.Chmod(docroot, 0o755); err != nil {
		return fmt.Errorf("setting permissions on docroot %s: %w", docroot, err)
	}

	if err := os.Chown(docroot, uid, gid); err != nil {
		return fmt.Errorf("setting ownership on docroot %s: %w", docroot, err)
	}

	return nil
}

// EnsureDocrootPrivileged exposes ensureDocrootPrivileged to web.apache.v1,
// which serves the very same per-domain docroot when apache is the domain's
// web server.
func EnsureDocrootPrivileged(cfg Config, username, resourceID string) error {
	return ensureDocrootPrivileged(cfg, username, resourceID)
}

// ensureDocrootPrivileged runs ensureDocroot directly (cfg.SudoBinary
// empty) or, in production, via this same binary's own
// "nginx-ensure-docroot" CLI mode under sudo: the intermediate
// "username/domains/resourceID" path components land inside
// Config.AccountsRoot/username, which identity's own ensureChrootTree
// creates root-owned per OpenSSH's own ChrootDirectory requirement, so an
// unprivileged lesta-agent-daemon cannot create anything beneath it
// directly -- confirmed directly deploying to a real node, mirroring
// identity's own ensureChrootTreePrivileged exactly.
func ensureDocrootPrivileged(cfg Config, username, resourceID string) error {
	if cfg.SudoBinary == "" || cfg.AgentBinaryPath == "" {
		return ensureDocroot(cfg, username, resourceID)
	}

	cmd := exec.Command(cfg.SudoBinary, cfg.AgentBinaryPath, "nginx-ensure-docroot", username, resourceID) //nolint:gosec // fixed binary, argv-validated username/resourceID, no shell

	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("ensuring docroot for %s/%s via sudo: %w: %s", username, resourceID, err, bytes.TrimSpace(out))
	}

	return nil
}

// EnsureDocroot is this CLI mode's own entry point (see
// cmd/lesta-agent/main.go's own "nginx-ensure-docroot" dispatch), invoked
// only via the narrowly-scoped sudoers rule
// .install/services/nginx/install.sh writes, which restricts the
// unprivileged lesta-agent-daemon to running exactly this subcommand (plus
// the real nginx binary) as root, nothing else. Returns a process exit
// code, matching identity.EnsureChrootTree's own shape.
func EnsureDocroot(cfg Config, username, resourceID string) int {
	if !docrootUsernamePattern.MatchString(username) {
		fmt.Fprintf(os.Stderr, "nginx-ensure-docroot: %q is not a valid username\n", username)

		return 1
	}

	if !docrootResourceIDPattern.MatchString(resourceID) {
		fmt.Fprintf(os.Stderr, "nginx-ensure-docroot: %q is not a valid resource id\n", resourceID)

		return 1
	}

	if err := ensureDocroot(cfg, username, resourceID); err != nil {
		fmt.Fprintln(os.Stderr, "nginx-ensure-docroot:", err)

		return 1
	}

	return 0
}
