package metrics

// Config parameterizes MetricsCapability by root paths and invocation
// details, so the identical implementation runs against a real node's own
// live logs/mailboxes/database in production or a fully disposable per-test
// fixture.
type Config struct {
	// NginxLogDir/ApacheLogDir are the exact same per-vhost access-log
	// directories nginx.Config.LogDir/apache.Config.LogDir point rendered
	// vhosts' own access_log/CustomLog directives at (one
	// <resource_id>.access.log per resource). A WebResourceRef's own
	// WebServer field selects which of the two a given resource_id's log
	// lives under.
	NginxLogDir  string
	ApacheLogDir string

	// VmailRoot is Dovecot's own real mailbox storage root
	// (VMAIL_HOME in .install/services/mail/install.sh,
	// maildir:<VmailRoot>/%d/%n in its own rendered dovecot config): a real,
	// recursive directory-size sum under VmailRoot/<domain>/<local_part> is
	// this capability's own disk-usage measurement for a MailAccountRef.
	VmailRoot string

	// MariaDBHost/MariaDBPort/MariaDBBinary address the real tenant MariaDB
	// instance a TenantDatabaseRef's own information_schema query runs
	// against -- the same real instance mariadb.Config already targets
	// (127.0.0.1:3307 in production), but connected to with each database's
	// own stats_user/stats_password from the payload, never a shared admin
	// credential: this capability only ever runs a read-only SELECT against
	// information_schema, matching the Statistics design's own "never share
	// credentials with tenant provisioning writes" constraint exactly.
	MariaDBHost   string
	MariaDBPort   int
	MariaDBBinary string

	// OffsetStateRoot is where this capability durably records, per
	// resource_id, the last byte offset already read from that resource's
	// own access log (see weblog.go's own doc comment for why this needs to
	// survive across separate daemon-dispatched observe calls, each its own
	// fresh process invocation).
	OffsetStateRoot string
}

func (c Config) mariadbBinary() string {
	if c.MariaDBBinary == "" {
		return "mariadb"
	}

	return c.MariaDBBinary
}

func (c Config) mariadbHost() string {
	if c.MariaDBHost == "" {
		return "127.0.0.1"
	}

	return c.MariaDBHost
}

// logDirFor returns the access-log directory for webServer ("nginx" or
// "apache"), already validated by ParsePayload against exactly those two
// values.
func (c Config) logDirFor(webServer string) string {
	if webServer == "apache" {
		return c.ApacheLogDir
	}

	return c.NginxLogDir
}
