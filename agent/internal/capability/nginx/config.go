package nginx

// Config parameterizes NginxCapability by root paths and invocation details, so
// the identical implementation runs against a real system-wide nginx install in
// production or a fully disposable per-test nginx instance.
type Config struct {
	// LiveDir is the directory nginx's main config includes via a fixed glob
	// (e.g. /etc/nginx/lesta.d), containing one <resource_id>.conf per active
	// resource plus transient .<resource_id>.conf.staging dotfiles.
	LiveDir string
	// StateRoot is the root generation history nests under (e.g.
	// /var/lib/lesta/nginx). Generations live at
	// StateRoot/domains/<resource_id>/generations/<n>/.
	StateRoot string
	// NginxConfPath is the real, read-only main nginx.conf. It must already
	// contain an `include <LiveDir>/*.conf;` line; this phase's code requires
	// that precondition, it does not create it.
	NginxConfPath string
	// NginxBinary is the nginx executable to invoke. Empty means "nginx"
	// resolved via PATH.
	NginxBinary string
	// Prefix is passed as nginx's -p flag when non-empty, relocating its
	// working directory (pid, temp paths) for a disposable per-test instance.
	// Empty means omit -p entirely, matching a real system-wide install.
	Prefix string
	// Port is the port every rendered vhost listens on (80 in production; an
	// ephemeral loopback port for a disposable test instance).
	Port int
	// ReloadCommand, when non-empty, fully overrides how a reload is issued
	// (e.g. ["systemctl", "reload", "nginx"]). This is the seam a later,
	// explicitly separate "Tier 2" suite against a real system-wide,
	// systemctl-managed nginx would use; it isn't exercised this phase. Empty
	// means the default `nginx -s reload [-p Prefix] -c NginxConfPath`.
	ReloadCommand []string
}

func (c Config) nginxBinary() string {
	if c.NginxBinary == "" {
		return "nginx"
	}

	return c.NginxBinary
}

// commandArgs prepends -p Prefix (when set) to extra, for every nginx
// invocation except a fully overridden ReloadCommand.
func (c Config) commandArgs(extra ...string) []string {
	args := make([]string, 0, len(extra)+2)

	if c.Prefix != "" {
		args = append(args, "-p", c.Prefix)
	}

	return append(args, extra...)
}
