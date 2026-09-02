package mariadb

import "strconv"

// Config parameterizes MariaDBCapability by connection details and root
// paths, so the identical implementation runs against a real, disposable
// per-test mariadbd instance or the real tenant MariaDB instance in
// production (.install/services/mariadb, port 3307).
type Config struct {
	// Host is the address the mariadb CLI connects to. Always 127.0.0.1 in
	// production: MySQL/MariaDB specifically special-case the literal
	// hostname "localhost" to mean Unix-socket-only, which this capability
	// never wants (see payload.go's own doc comment on why every GRANT this
	// capability issues also targets '127.0.0.1', never 'localhost').
	Host string
	// Port is the tenant MariaDB instance's own port (3307 in production;
	// an ephemeral loopback port for a disposable test instance).
	Port int
	// MariaDBBinary is the mariadb client executable to invoke. Empty means
	// "mariadb" resolved via PATH. Fixed, never overridable by payload
	// content: it is passed straight into an exec.Command call (see
	// exec.go), so making it externally overridable would let anything able
	// to set this process's environment redirect a privileged exec to an
	// arbitrary executable -- the same rationale nginx/apache/bind9's own
	// Config.*Binary fields already document.
	MariaDBBinary string
	// DefaultsExtraFile is passed as the mariadb CLI's own
	// --defaults-extra-file flag: a root-owned, 0600 ini file carrying the
	// admin credentials this capability authenticates with (the standard
	// MariaDB mechanism for keeping credentials out of argv/env/`ps`,
	// mirroring bind9's own rndc key file). It must be the very first
	// argument on the command line -- MariaDB/MySQL clients only honor
	// --defaults-extra-file when it precedes every other option.
	DefaultsExtraFile string
	// StateRoot is the root this capability's own generation-history
	// bookkeeping (idempotency ledger, observe's own drift-detection
	// manifests) nests under. Deliberately NOT the MariaDB server's own
	// datadir: that path is owned and written by mariadbd itself (as the
	// `mysql` system user), never by this capability, which only ever
	// speaks to it over its SQL client protocol, so this capability's own
	// bookkeeping lives at a separate path it owns outright.
	StateRoot string
}

func (c Config) mariadbBinary() string {
	if c.MariaDBBinary == "" {
		return "mariadb"
	}

	return c.MariaDBBinary
}

func (c Config) host() string {
	if c.Host == "" {
		return "127.0.0.1"
	}

	return c.Host
}

// baseArgs returns the fixed argument prefix every mariadb CLI invocation
// this capability makes starts with: --defaults-extra-file first (a hard
// MariaDB client requirement), then connection details, then --batch
// --skip-column-names (so SELECT output is easy to parse: tab-separated
// values, no header row, no ASCII-art table borders) and --disable-reconnect
// (make a dropped connection a hard error, never a silent transparent
// retry that could re-run a statement against a different session state).
func (c Config) baseArgs() []string {
	args := make([]string, 0, 8)

	if c.DefaultsExtraFile != "" {
		args = append(args, "--defaults-extra-file="+c.DefaultsExtraFile)
	}

	args = append(args,
		"--host="+c.host(),
		"--port="+strconv.Itoa(c.Port),
		"--protocol=TCP",
		"--batch",
		"--skip-column-names",
		"--disable-reconnect",
	)

	return args
}
