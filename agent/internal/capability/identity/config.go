package identity

import (
	"context"
	"os/exec"
)

// Config parameterizes IdentityCapability by the external binaries it execs
// and the paths its real SFTP/chroot rendering (Web Application Hosting
// Threat Model and Isolation Design.md step 2) writes to, so the identical
// implementation runs against a real host or a disposable per-test
// environment. Mirrors nginx.Config/mariadb.Config's own *Binary field
// pattern: every field here is fixed at process start
// (cmd/lesta-agent/main.go's own identityProductionConfig), never
// overridable via payload content or environment.
type Config struct {
	// UseraddBinary is the useradd executable to invoke for create. Empty
	// means "useradd" resolved via PATH.
	UseraddBinary string
	// UserdelBinary is the userdel executable to invoke for delete. Empty
	// means "userdel" resolved via PATH.
	UserdelBinary string
	// IDBinary is the id executable used to check whether a username
	// already exists before attempting create (or already doesn't before
	// attempting delete), keeping both verbs idempotent. Empty means "id"
	// resolved via PATH.
	IDBinary string

	// AccountsRoot is where each account's own chroot tree lives:
	// AccountsRoot/<username>/ (root-owned, mode 0755, the real OpenSSH
	// ChrootDirectory ownership requirement) containing AccountsRoot/
	// <username>/public/ (owned by <username>, mode 0750, where a future
	// web.php-fpm.v1 and this account's own SFTP session both read/write).
	// Production: /var/lib/lesta/web/accounts.
	AccountsRoot string
	// SftpConfigDir is the directory sshd's own config already includes by
	// default on Ubuntu 24.04/26.04 (Include /etc/ssh/sshd_config.d/*.conf
	// ships in the stock sshd_config -- confirmed, not assumed, by this
	// phase's own CI), containing one <resource_id>.conf Match block per
	// account plus transient .<resource_id>.conf.staging dotfiles. Unlike
	// nginx/bind9/apache, this needs no manual operator include-line
	// prerequisite: LESta is creating a new file under an already-included
	// directory, never editing a shared file it doesn't own.
	SftpConfigDir string
	// AuthorizedKeysDir is where each account's own single-line
	// authorized_keys file lives, named <username> (sshd's global
	// AuthorizedKeysFile directive, rendered once into SftpConfigDir's own
	// static prerequisite file, adds this path via a %u substitution
	// alongside the default ".ssh/authorized_keys" -- not in place of it,
	// since every other account on the box still needs its own default
	// lookup to keep working) -- centralized rather than
	// per-home-directory ~/.ssh/authorized_keys, since every account's own
	// Linux user is created --no-create-home. Production:
	// /etc/lesta/sftp/authorized_keys.
	AuthorizedKeysDir string
	// StateRoot is the root generation history nests under (e.g.
	// /var/lib/lesta/identity). Generations live at
	// StateRoot/accounts/<resource_id>/generations/<n>/.
	StateRoot string
	// SshdBinary is the sshd executable used for `-t` config validation.
	// Empty means "sshd" resolved via PATH.
	SshdBinary string
	// SshdConfigPath is the read-only file buildSyntheticConfig (validate.go)
	// scans for the one `Include <SftpConfigDir>/*.conf` line it swaps to
	// build a candidate validation config; never written to. In the
	// disposable test harness this is the single flat sshd_config sshd
	// itself is launched with (Include lives directly in it). In
	// production it is deliberately NOT the real top-level
	// /etc/ssh/sshd_config -- that file only Includes
	// /etc/ssh/sshd_config.d/*.conf (Ubuntu's own stock default, matching
	// every *.conf dropped there, not specifically SftpConfigDir); the
	// actual `Include <SftpConfigDir>/*.conf` line lives one level deeper,
	// in agent-daemon/install.sh's own one-time
	// /etc/ssh/sshd_config.d/00-lesta.conf. Found deploying to a real
	// node: pointing this at the real top-level file (as this field's own
	// name suggests) made every real create/update fail validation with
	// "has no Include ... line", since the test harness's own flat,
	// single-file structure had never surfaced that these are two
	// different files in production.
	SshdConfigPath string
	// Port is the port sshd listens on: 22 in production, an ephemeral
	// loopback port for a disposable test instance.
	Port int
	// ReloadCommand, when non-empty, fully overrides how a reload is
	// issued (e.g. ["systemctl", "reload", "ssh"] -- Ubuntu's own sshd
	// service is literally named "ssh", not "sshd"). Empty means the
	// disposable-test default of sending SIGHUP to ReloadPID.
	ReloadCommand []string
	// ReloadPID, when ReloadCommand is empty, is the running sshd master's
	// own pid, signaled with SIGHUP directly -- the seam a disposable test
	// harness uses instead of systemctl.
	ReloadPID int
	// SudoBinary, when non-empty, routes useradd/userdel (createSystemUser,
	// deleteSystemUser) through "<SudoBinary> <resolved binary> ...".
	// Production sets this to "sudo": the real lesta-agent-daemon systemd
	// unit runs as the unprivileged lesta-agent user, confirmed directly
	// deploying to a real node -- useradd/userdel always require root
	// (they write /etc/passwd/, /etc/shadow, /etc/group directly; there is
	// no file-permission fix, only running the process itself as root).
	// .install/services/agent-daemon/install.sh's own sudoers rule scopes
	// exactly which two binaries this may run. Empty means invoke both
	// directly, matching this package's own disposable test harness, which
	// already runs with whatever privilege `go test` itself has.
	SudoBinary string
	// AgentBinaryPath is this same lesta-agent binary's own real installed
	// path (.install/lib/agent.sh's own AGENT_BINARY_DEST), used together
	// with SudoBinary to re-invoke this binary's own
	// "identity-ensure-chroot-tree" CLI mode as root: ensureChrootTree's
	// own os.MkdirAll/os.Chown calls (privileged.go) require real root,
	// which sudo cannot grant to an in-process Go syscall the way it can a
	// separate exec.Command invocation -- confirmed directly deploying to
	// a real node. Empty means EnsureChrootTree runs in-process directly,
	// matching this package's own disposable test harness.
	AgentBinaryPath string
}

// command builds an *exec.Cmd for binary+args, routed through
// "<SudoBinary> <binary> <args...>" when SudoBinary is set, or invoking
// binary directly otherwise. Shared by createSystemUser/deleteSystemUser
// (exec.go); mirrors nginx.Config's/bind9.Config's own command() helper
// exactly.
func (c Config) command(ctx context.Context, binary string, args ...string) *exec.Cmd {
	if c.SudoBinary == "" {
		return exec.CommandContext(ctx, binary, args...)
	}

	return exec.CommandContext(ctx, c.SudoBinary, append([]string{binary}, args...)...)
}

func (c Config) useraddBinary() string {
	if c.UseraddBinary == "" {
		return "useradd"
	}

	return c.UseraddBinary
}

func (c Config) userdelBinary() string {
	if c.UserdelBinary == "" {
		return "userdel"
	}

	return c.UserdelBinary
}

func (c Config) idBinary() string {
	if c.IDBinary == "" {
		return "id"
	}

	return c.IDBinary
}

func (c Config) sshdBinary() string {
	if c.SshdBinary == "" {
		return "sshd"
	}

	return c.SshdBinary
}
