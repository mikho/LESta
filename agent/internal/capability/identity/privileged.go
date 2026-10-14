package identity

import (
	"bytes"
	"fmt"
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

// accountUsernamePattern is the only kind of name the root-only useradd/userdel modes accept: the
// per-account name the control plane derives ("lesta-t" and the account id). Unlike
// cliUsernamePattern it cannot name root, the daemon's own users or any other existing account, so
// a daemon that is asked, or tricked, into deleting "root" cannot do it.
var accountUsernamePattern = regexp.MustCompile(`^lesta-t[0-9]{1,12}$`)

// SystemUserAdd is the "identity-useradd" CLI mode: it creates one per-account system user with
// the fixed flags createSystemUser has always used. Run as root through the sudoers rule
// agent-daemon/install.sh writes, which allows exactly this subcommand and not useradd itself.
func SystemUserAdd(cfg Config, username string) int {
	if !accountUsernamePattern.MatchString(username) {
		fmt.Fprintf(os.Stderr, "identity-useradd: %q is not a per-account username\n", username)

		return 1
	}

	cmd := exec.Command(cfg.useraddBinary(), systemUserArgs(username)...) //nolint:gosec // fixed binary, fixed flags, validated username, no shell

	if out, err := cmd.CombinedOutput(); err != nil {
		fmt.Fprintf(os.Stderr, "identity-useradd: %v: %s\n", err, bytes.TrimSpace(out))

		return 1
	}

	return 0
}

// SystemUserDel is the "identity-userdel" CLI mode, the counterpart of SystemUserAdd.
func SystemUserDel(cfg Config, username string) int {
	if !accountUsernamePattern.MatchString(username) {
		fmt.Fprintf(os.Stderr, "identity-userdel: %q is not a per-account username\n", username)

		return 1
	}

	cmd := exec.Command(cfg.userdelBinary(), username) //nolint:gosec // fixed binary, validated username, no shell

	if out, err := cmd.CombinedOutput(); err != nil {
		fmt.Fprintf(os.Stderr, "identity-userdel: %v: %s\n", err, bytes.TrimSpace(out))

		return 1
	}

	return 0
}

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
