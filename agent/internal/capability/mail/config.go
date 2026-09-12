package mail

// Config parameterizes MailCapability by root paths and invocation details,
// so the identical implementation runs against a real system-wide Exim +
// Dovecot install in production or a fully disposable per-test pair of
// instances.
//
// Unlike bind9 (one resource, one glob-included stanza fragment), Exim has
// no native glob-file-include mechanism for its own main config, and its
// lsearch/dsearch lookup types are either keyed by a single flat file or (for
// dsearch, a directory-of-files lookup) not reliably available across real
// Exim builds. This capability instead maintains a small set of AGGREGATE
// data files -- one line per hosted domain/account across every MailDomain
// resource this node knows about -- rewritten in full on every apply by
// scanning this package's own StateRoot (see meta.go's listKnownDomains),
// mirroring the same stage-validate-activate-reload-healthcheck discipline
// every other capability uses, just applied to lookup DATA rather than to
// config STRUCTURE. The generic Exim router/transport/ACL/authenticator
// STRUCTURE (which never changes per-domain) is a fixed, pre-existing,
// operator/installer-provided prerequisite, exactly like nginx.conf's own
// required include line.
type Config struct {
	// EximStaticConfPath is the real, read-only Exim main config file. It
	// must already contain the generic LESta virtual-mail router/transport/
	// ACL/authenticator block (see docs/mail-static-config.md, written once
	// by an operator or a future installer phase, never by this capability).
	EximStaticConfPath string
	// EximDataDir is the directory the static config's own lookups point
	// into (e.g. /etc/exim4/lesta.d/data): domains.list, accounts.list,
	// antivirus.list, antispam.list, dkim_keys.list, one line per active
	// resource, rewritten atomically on every apply.
	EximDataDir string
	// EximBinary is the exim executable to invoke for validation. Empty
	// means "exim" resolved via PATH.
	EximBinary string
	// EximReloadCommand, when non-empty, fully overrides how an Exim reload
	// is issued (e.g. for failure-injection tests or a real `systemctl
	// reload exim4`). Empty means a direct SIGHUP to the daemon named by
	// EximPIDFile.
	EximReloadCommand []string
	// EximPIDFile is the running Exim daemon's pid file, used for the
	// default SIGHUP reload when EximReloadCommand is empty.
	EximPIDFile string
	// EximListenAddress/EximListenPort is where this node's Exim accepts
	// SMTP, used by the real end-to-end health check. Empty address means
	// 127.0.0.1.
	EximListenAddress string
	EximListenPort    int

	// DovecotConfPath is the real, read-only Dovecot main config file
	// (equivalent role to EximStaticConfPath): a fixed structural
	// prerequisite (protocols, passdb/userdb pointed at DovecotPasswdPath,
	// lmtp/sieve/quota plugins enabled) this capability never rewrites.
	DovecotConfPath string
	// DovecotPasswdPath is the Dovecot passwd-file passdb/userdb data file
	// this capability rewrites atomically on every apply: one line per
	// active (non-suspended) MailAccount across every hosted domain.
	DovecotPasswdPath string
	// DovecotBinary/DoveadmBinary/DoveconfBinary are the executables to
	// invoke. Empty means resolved via PATH.
	DovecotBinary  string
	DoveadmBinary  string
	DoveconfBinary string
	// DovecotReloadCommand, when non-empty, fully overrides how a Dovecot
	// reload is issued. Empty means `doveadm reload`.
	DovecotReloadCommand []string

	// SieveDir is the root sieve scripts are rendered under
	// (SieveDir/<domain>/<local_part>.sieve), one file per account,
	// mirroring bind9's own per-resource-file precedent exactly (no
	// aggregation needed here, unlike the Exim/Dovecot lookup data above).
	SieveDir string
	// SievecBinary is the Pigeonhole sieve compiler used to validate a
	// rendered script before activation. Empty means "sievec" resolved via
	// PATH.
	SievecBinary string

	// DKIMKeyRoot is the root DKIM private keys are generated under
	// (DKIMKeyRoot/<domain>/<selector>.private), owned exclusively by the
	// mail service's own OS identity, mode 0600. Per the Mail Threat
	// Model's own non-negotiable prohibition, the private key is generated
	// here and NEVER leaves this node: it is not recorded in any
	// ResultEnvelope, generation meta returned to the control plane, or
	// payload of any kind. Only the derived public key/selector are ever
	// disclosed (see dkim.go's own doc comment for the still-open question
	// of how that reaches DNS).
	DKIMKeyRoot string
	// OpensslBinary is the openssl executable used for real DKIM keypair
	// generation. Empty means "openssl" resolved via PATH.
	OpensslBinary string

	// StateRoot is the root this capability's own generation history and
	// sibling-domain bookkeeping nest under (e.g. /var/lib/lesta/mail).
	// Generations live at StateRoot/domains/<resource_id>/generations/<n>/.
	StateRoot string
}

func (c Config) eximBinary() string {
	if c.EximBinary == "" {
		return "exim"
	}

	return c.EximBinary
}

func (c Config) doveadmBinary() string {
	if c.DoveadmBinary == "" {
		return "doveadm"
	}

	return c.DoveadmBinary
}

func (c Config) doveconfBinary() string {
	if c.DoveconfBinary == "" {
		return "doveconf"
	}

	return c.DoveconfBinary
}

func (c Config) sievecBinary() string {
	if c.SievecBinary == "" {
		return "sievec"
	}

	return c.SievecBinary
}

func (c Config) opensslBinary() string {
	if c.OpensslBinary == "" {
		return "openssl"
	}

	return c.OpensslBinary
}

func (c Config) eximListenAddress() string {
	if c.EximListenAddress == "" {
		return "127.0.0.1"
	}

	return c.EximListenAddress
}
