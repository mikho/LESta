package apache

// Config parameterizes ApacheCapability by root paths and invocation details, so
// the identical implementation runs against a real system-wide apache2 install in
// production or a fully disposable per-test instance.
type Config struct {
	// LiveDir is the directory apache2's main config includes via a fixed glob
	// (e.g. /etc/apache2/lesta.d), containing one <resource_id>.conf per active
	// resource plus transient .<resource_id>.conf.staging dotfiles, plus the
	// static 00-lesta-modules.conf fragment (see content.go's
	// ensureModulesFragment).
	LiveDir string
	// StateRoot is the root generation history nests under (e.g.
	// /var/lib/lesta/apache). Generations live at
	// StateRoot/domains/<resource_id>/generations/<n>/.
	StateRoot string
	// ApacheConfPath is the real, read-only main apache2.conf. It must already
	// contain an `IncludeOptional <LiveDir>/*.conf` line; this phase's code
	// requires that precondition, it does not create it.
	ApacheConfPath string
	// ApacheBinary is the apache2 executable to invoke. Empty means "apache2"
	// resolved via PATH (the real Ubuntu/Debian binary name; "httpd" is only
	// ever used by the disposable test harness on this dev Mac).
	ApacheBinary string
	// Prefix is passed as apache2's -d flag when non-empty, relocating its
	// ServerRoot for a disposable per-test instance. Empty means omit -d
	// entirely, matching a real system-wide install.
	Prefix string
	// Port is the port every rendered vhost listens on (80 in production; an
	// ephemeral loopback port for a disposable test instance).
	Port int
	// Env is the fixed "KEY=VALUE" list applied wholesale (never merged with
	// or inherited from this process's own environment) to every apache2 exec
	// invocation. Real apache2.conf depends on ${APACHE_RUN_USER} etc.,
	// normally resolved by apache2ctl sourcing /etc/apache2/envvars before
	// exec; since this package execs the raw binary directly (mirroring
	// nginx's own bypass of wrapper scripts), it must supply the same fixed
	// values itself. Hardcoded at the Go level by apacheProductionConfig() in
	// main.go, never read from this process's own environment or any flag or
	// CLI argument. Empty for the disposable test harness, whose own
	// from-scratch synthetic config never references ${APACHE_...} at all.
	Env []string
	// ReloadCommand, when non-empty, fully overrides how a reload is issued
	// (e.g. ["systemctl", "reload", "apache2"]). This is the same
	// failure-injection seam nginx's own tests already use; it isn't
	// exercised this phase in production. Empty means the default
	// `apache2 -k graceful [-d Prefix] -f ApacheConfPath`.
	ReloadCommand []string
	// SSLPort is the port an SSL-capable vhost's second <VirtualHost> block
	// listens on: 443 in production (standalone apache profile only), 0 in
	// the "both" web profile (Apache is a loopback-only 8080 backend behind
	// nginx there and must never bind 443 itself, avoiding a bind conflict
	// with nginx's own public 443 listener), an ephemeral loopback port for a
	// disposable test instance. 0 also means "no SSL vhost ever selected":
	// renderVhost's own selection is CertificatePath != "", and by
	// construction WebDomain never dispatches an SSL-bearing payload to
	// Apache when it isn't the domain's resolved public capability (see
	// WebDomain::toProvisioningPayload()), so this field's only real job in
	// production is suppressing the global Listen directive in the "both"
	// profile, not gating template selection itself.
	SSLPort int
	// AcmeChallengeDir is the directory tls.acme.v1 writes HTTP-01 challenge
	// files into (production: /var/lib/lesta/acme/http-01, the same path
	// internal/capability/nginx's own Config.AcmeChallengeDir resolves to
	// and internal/capability/acme's own Config.StateRoot+"/http-01"
	// produces). Every rendered vhost template (default, suspended, and the
	// new SSL one) gets an Alias for this directory, so HTTP-01 challenges
	// keep working for a suspended or freshly-created domain the same way
	// nginx's own templates already do.
	AcmeChallengeDir string
}

func (c Config) apacheBinary() string {
	if c.ApacheBinary == "" {
		return "apache2"
	}

	return c.ApacheBinary
}

// commandArgs prepends -d Prefix (when set) to extra, for every apache2
// invocation except a fully overridden ReloadCommand.
func (c Config) commandArgs(extra ...string) []string {
	args := make([]string, 0, len(extra)+2)

	if c.Prefix != "" {
		args = append(args, "-d", c.Prefix)
	}

	return append(args, extra...)
}
