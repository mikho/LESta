package identity

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
	// static prerequisite file, points here via a %u substitution) --
	// centralized rather than per-home-directory ~/.ssh/authorized_keys,
	// since every account's own Linux user is created --no-create-home.
	// Production: /etc/lesta/sftp/authorized_keys.
	AuthorizedKeysDir string
	// StateRoot is the root generation history nests under (e.g.
	// /var/lib/lesta/identity). Generations live at
	// StateRoot/accounts/<resource_id>/generations/<n>/.
	StateRoot string
	// SshdBinary is the sshd executable used for `-t` config validation.
	// Empty means "sshd" resolved via PATH.
	SshdBinary string
	// SshdConfigPath is the real, read-only main sshd_config this node's
	// real sshd daemon reads. Used only to build a synthetic validation
	// config (validate.go); never written to.
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
