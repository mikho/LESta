package identity

import (
	"bytes"
	"fmt"
	"io"
	"os"
	"os/exec"
	"regexp"
)

// cliUsernamePattern mirrors payload.go's own usernamePattern exactly.
// Unlike that one (already validated once by ParsePayload before ever
// reaching this package's own write paths), a username reaching
// EnsureChrootTree crosses a real process boundary (sudo, running as root)
// rather than just an in-process call, so it is re-validated here from
// scratch: this package's own security boundary for that boundary must
// hold on its own, never by trusting that some earlier layer already
// checked -- the same discipline cron's own resourceIDPattern/runAsPattern
// re-checks already established.
var cliUsernamePattern = regexp.MustCompile(`^[a-z][a-z0-9_-]{0,31}$`)

// ensureChrootTreePrivileged runs ensureChrootTree directly (cfg.SudoBinary
// empty) or, in production, via this same binary's own
// "identity-ensure-chroot-tree" CLI mode under sudo: ensureChrootTree's own
// os.MkdirAll under Config.AccountsRoot (root:lesta, never group-writable --
// see agent-daemon/install.sh's own bootstrap_sftp_prerequisite) and its
// two os.Chown calls (one to root:root, one to the tenant's own uid/gid)
// all require real root, confirmed directly deploying to a real node:
// there is no unprivileged equivalent of "chown to an arbitrary uid/gid",
// unlike a plain file write.
func ensureChrootTreePrivileged(cfg Config, username string) error {
	if cfg.SudoBinary == "" || cfg.AgentBinaryPath == "" {
		return ensureChrootTree(cfg, username)
	}

	cmd := exec.Command(cfg.SudoBinary, cfg.AgentBinaryPath, "identity-ensure-chroot-tree", username) //nolint:gosec // fixed binary, argv-validated username, no shell

	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("ensuring chroot tree for %s via sudo: %w: %s", username, err, bytes.TrimSpace(out))
	}

	return nil
}

// EnsureChrootTree is this CLI mode's own entry point (see
// cmd/lesta-agent/main.go's own "identity-ensure-chroot-tree" dispatch),
// invoked only via the narrowly-scoped sudoers rule
// agent-daemon/install.sh writes, which restricts the unprivileged
// lesta-agent-daemon to running exactly this subcommand (plus useradd/
// userdel) as root, nothing else. Returns a process exit code, matching
// cron's own InstallFragment/EnsureAccountDir shape.
func EnsureChrootTree(cfg Config, username string) int {
	if !cliUsernamePattern.MatchString(username) {
		fmt.Fprintf(os.Stderr, "identity-ensure-chroot-tree: %q is not a valid username\n", username)

		return 1
	}

	if err := ensureChrootTree(cfg, username); err != nil {
		fmt.Fprintln(os.Stderr, "identity-ensure-chroot-tree:", err)

		return 1
	}

	return 0
}

// writeAuthorizedKeysPrivileged runs writeAuthorizedKeys directly
// (cfg.SudoBinary empty) or, in production, via this same binary's own
// "identity-write-authorized-keys" CLI mode under sudo, content piped over
// stdin (never argv -- key material can be long and sshd's own supported
// key types are not shell-safe to assume): mirrors
// cron's own installSidecar/"cron-install-sidecar" pair exactly, for the
// identical reason -- see writeAuthorizedKeys's own doc comment for why
// AuthorizedKeysDir itself grants the real unprivileged lesta-agent-daemon
// no write access at all.
func writeAuthorizedKeysPrivileged(cfg Config, username string, content []byte) error {
	if cfg.SudoBinary == "" || cfg.AgentBinaryPath == "" {
		return writeAuthorizedKeys(cfg, username, content)
	}

	cmd := exec.Command(cfg.SudoBinary, cfg.AgentBinaryPath, "identity-write-authorized-keys", username) //nolint:gosec // fixed binary, argv-validated username, no shell
	cmd.Stdin = bytes.NewReader(content)

	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("writing authorized_keys for %s via sudo: %w: %s", username, err, bytes.TrimSpace(out))
	}

	return nil
}

// WriteAuthorizedKeys is this CLI mode's own entry point (see
// cmd/lesta-agent/main.go's own "identity-write-authorized-keys" dispatch),
// invoked only via the narrowly-scoped sudoers rule
// agent-daemon/install.sh writes. Returns a process exit code, matching
// EnsureChrootTree's own shape.
func WriteAuthorizedKeys(cfg Config, username string, r io.Reader) int {
	if !cliUsernamePattern.MatchString(username) {
		fmt.Fprintf(os.Stderr, "identity-write-authorized-keys: %q is not a valid username\n", username)

		return 1
	}

	content, err := io.ReadAll(r)
	if err != nil {
		fmt.Fprintln(os.Stderr, "identity-write-authorized-keys: reading content from stdin:", err)

		return 1
	}

	if err := writeAuthorizedKeys(cfg, username, content); err != nil {
		fmt.Fprintln(os.Stderr, "identity-write-authorized-keys:", err)

		return 1
	}

	return 0
}
