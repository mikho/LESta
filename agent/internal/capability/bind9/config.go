package bind9

// Config parameterizes Bind9Capability by root paths and invocation details, so
// the identical implementation runs against a real system-wide BIND9 install in
// production or a fully disposable per-test named instance.
type Config struct {
	// LiveDir is the directory named's main config includes via a fixed glob
	// (e.g. /etc/bind/lesta.d), containing one <resource_id>.conf stanza per
	// active zone plus transient .<resource_id>.conf.staging dotfiles.
	LiveDir string
	// StateRoot is the root generation history nests under (e.g.
	// /var/lib/lesta/bind). Generations live at
	// StateRoot/zones/<resource_id>/generations/<n>/.
	StateRoot string
	// NamedConfPath is the real, read-only main named.conf. It must already
	// contain an `include "<LiveDir>/*.conf";` line; this phase's code
	// requires that precondition, it does not create it.
	NamedConfPath string
	// NamedCheckconfBinary is the named-checkconf executable to invoke. Empty
	// means "named-checkconf" resolved via PATH.
	NamedCheckconfBinary string
	// RndcBinary is the rndc executable to invoke. Empty means "rndc"
	// resolved via PATH.
	RndcBinary string
	// RndcConfigPath is passed as rndc's -c flag when non-empty, pointing
	// rndc at a disposable per-test rndc.conf (its own control-channel
	// address/port/key). Empty means the system default (rndc.conf's usual
	// location), matching a real system-wide install.
	RndcConfigPath string
	// ListenAddress is the address health checks query named on. Empty means
	// "127.0.0.1".
	ListenAddress string
	// Port is the port named listens on for DNS (53 in production; an
	// ephemeral loopback port for a disposable test instance).
	Port int
	// Nameservers is the fixed set of out-of-bailiwick nameserver FQDNs
	// (trailing dot) this node advertises for every zone it serves,
	// synthesized as each zone's apex NS set and SOA MNAME at render time.
	// Must be non-empty; New does not validate this itself, since it cannot
	// return an error. An empty list surfaces as a plain Go rendering error
	// (the "no verdict reached" bucket) the first time a zone is rendered,
	// never as a rejected ResultEnvelope: it is a deployment
	// misconfiguration, not a tenant-input problem.
	Nameservers []string
	// ReloadCommand, when non-empty, fully overrides how a reload is issued
	// (e.g. for failure-injection tests). Empty means the default
	// `rndc [-c RndcConfigPath] reload`.
	ReloadCommand []string
}

func (c Config) namedCheckconfBinary() string {
	if c.NamedCheckconfBinary == "" {
		return "named-checkconf"
	}

	return c.NamedCheckconfBinary
}

func (c Config) rndcBinary() string {
	if c.RndcBinary == "" {
		return "rndc"
	}

	return c.RndcBinary
}

// rndcArgs prepends -c RndcConfigPath (when set) to extra, for every rndc
// invocation except a fully overridden ReloadCommand.
func (c Config) rndcArgs(extra ...string) []string {
	args := make([]string, 0, len(extra)+2)

	if c.RndcConfigPath != "" {
		args = append(args, "-c", c.RndcConfigPath)
	}

	return append(args, extra...)
}

func (c Config) listenAddress() string {
	if c.ListenAddress == "" {
		return "127.0.0.1"
	}

	return c.ListenAddress
}
