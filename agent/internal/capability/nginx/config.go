package nginx

import (
	"context"
	"os/exec"
)

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
	// LogDir is the directory every rendered vhost's own access_log
	// directive points into: one <resource_id>.access.log per resource
	// (e.g. /var/log/lesta/nginx), never a single combined log. Per-vhost
	// files, keyed by resource_id rather than the tenant-controlled domain
	// string (matching LiveDir's own <resource_id>.conf convention exactly),
	// are what metrics.usage.v1 later reads and truncates to collect real
	// bandwidth/request-count usage per domain, with no per-line Host-header
	// attribution needed.
	LogDir string
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
	// ProxyBackend is the "host:port" a rendered apache-proxy vhost's
	// proxy_pass directive points at (127.0.0.1:8080 in production, matching
	// .install/profiles/schema.json's own hardcoded backend port for the
	// "both" web profile). Empty/overridable in the disposable test harness,
	// which points it at whatever ephemeral loopback port its own disposable
	// Apache instance is listening on. Unused for every other web_template.
	ProxyBackend string
	// AcmeChallengeDir is the directory every rendered vhost's shared
	// `location /.well-known/acme-challenge/` block serves from, via an
	// nginx `alias` (not `root`: tls.acme.v1 writes challenge files directly
	// at <dir>/<token>, with no nested .well-known/acme-challenge/ path of
	// its own, so `alias` -- which replaces the matched location prefix
	// outright -- is the directive that actually matches that layout; `root`
	// would instead require the request's full URI appended beneath <dir>,
	// which is not where the files land). Production:
	// /var/lib/lesta/acme/http-01, the exact same path
	// internal/capability/acme's own Config.StateRoot+"/http-01" resolves
	// to. Threaded through Config (like ProxyBackend above) rather than
	// hardcoded in the template, so a disposable test instance can point it
	// at its own temp directory instead.
	AcmeChallengeDir string
	// AccountsRoot is system.account-identity.v1's own chroot accounts
	// root (identity.Config's own field of the same name); this
	// capability never writes there, only reads the convention to
	// compute each domain's own per-domain docroot
	// (AccountsRoot/<account_id>/domains/<resource_id>/public) for the
	// real content+FastCGI template. Production: /var/lib/lesta/web/accounts.
	AccountsRoot string
	// SSLPort is the port templates/default_ssl.conf.tmpl's second (HTTPS)
	// server block listens on: 443 in production, an ephemeral loopback
	// port for a disposable test instance (binding to the literal port 443
	// requires root, which a disposable per-test process never has).
	// Unused by every template that never selects default_ssl.conf.tmpl.
	SSLPort int
	// ReloadCommand, when non-empty, fully overrides how a reload is issued
	// (e.g. ["systemctl", "reload", "nginx"]). This is the seam a later,
	// explicitly separate "Tier 2" suite against a real system-wide,
	// systemctl-managed nginx would use; it isn't exercised this phase. Empty
	// means the default `nginx -s reload [-p Prefix] -c NginxConfPath`.
	ReloadCommand []string
	// SudoBinary, when non-empty, routes both real root-only nginx
	// invocations (validate's own `-t`, and reload's default `-s reload`)
	// through "<SudoBinary> <nginxBinary()> ...". Production sets this to
	// "sudo": the real lesta-agent-daemon systemd unit runs as the
	// unprivileged lesta-agent user, confirmed directly deploying to a
	// real node -- nginx itself refuses to even test or reload a config
	// that references a real, correctly root-protected TLS private key
	// (any coexisting HTTPS vhost's own, not just one of this
	// capability's own fragments) unless run as root, since nginx always
	// re-parses and opens every referenced certificate as part of both
	// operations, not just the one fragment actually being validated or
	// activated. .install/services/nginx/install.sh's own sudoers rule
	// scopes exactly which binary this may run, nothing else. Empty means
	// invoke nginx directly, matching this package's own disposable test
	// harness, which already runs with whatever privilege `go test`
	// itself has.
	SudoBinary string
}

func (c Config) nginxBinary() string {
	if c.NginxBinary == "" {
		return "nginx"
	}

	return c.NginxBinary
}

// command builds the real nginx invocation for args, routed through
// SudoBinary when set (see its own doc comment on Config for why: nginx
// always re-parses and opens every referenced TLS private key across the
// whole real config for both -t and -s reload, not just whatever this
// capability's own fragment is doing, and a real, correctly root-protected
// key refuses to open for anything but root).
func (c Config) command(ctx context.Context, args ...string) *exec.Cmd {
	if c.SudoBinary == "" {
		return exec.CommandContext(ctx, c.nginxBinary(), args...)
	}

	return exec.CommandContext(ctx, c.SudoBinary, append([]string{c.nginxBinary()}, args...)...)
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
