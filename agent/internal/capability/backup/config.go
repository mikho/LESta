package backup

// Config parameterizes BackupCapability by the fixed, root-owned artifact
// directory it writes to and the fixed set of other capabilities' own state
// roots it may snapshot. Every field here is fixed at process start
// (cmd/lesta-agent/main.go's own backupProductionConfig), never overridable
// via payload content, mirroring every other real capability's own Config
// discipline.
type Config struct {
	// ArtifactsRoot is the one owned directory
	// (.install/services/backups/manifest.json's own owned_roots) every
	// backup artifact is written under and read back from on delete. Must
	// exist (or be creatable) and be writable.
	ArtifactsRoot string

	// StateRoots maps a backed-up capability's own protocol name (e.g.
	// "web.nginx.v1") to the fixed directory that capability's own Config
	// renders its live state under (e.g. nginx.Config's own StateRoot).
	// Backups discovers which of these are actually present and non-empty
	// on this node at apply time; whichever qualify are the "included
	// capabilities" a given artifact actually captures. There is no
	// hardcoded expectation that every entry is present on every node: a
	// node running only dns.bind9.v1 backs up only its own bind state.
	//
	// Deliberately never database.control-plane.v1/database.tenant.v1's own
	// real datadir: those two are captured via DatabaseDumpSockets instead
	// (a real mysqldump/mariadb-dump, not a raw filesystem copy of a live
	// InnoDB directory). database.tenant.v1's own small, redacted
	// generation-history bookkeeping directory (never the datadir itself)
	// is safe to include here like any other capability's StateRoot.
	StateRoots map[string]string

	// DatabaseDumpSockets maps database.control-plane.v1/database.tenant.v1
	// to the real local unix socket path a real, live MariaDB instance is
	// listening on (e.g. /run/mysqld/mysqld.tenant.sock), the same sockets
	// this project's own installer health checks already authenticate
	// against as root via unix_socket auth. A missing entry, or an entry
	// whose socket file doesn't exist on disk, means that instance simply
	// isn't running on this node, not an error -- mirroring StateRoots'
	// own "absent = not present here" convention exactly.
	DatabaseDumpSockets map[string]string

	// VMailRoot is mail.smtp-imap.v1's own real Dovecot virtual-mailbox root
	// (.install/services/mail/install.sh's own VMAIL_HOME, a subdirectory
	// of its StateRoot) -- the one thing on a node genuinely irreplaceable
	// from Laravel's own database (real received email), which restore.go's
	// own applyRestore puts back. Never used by create/delete/observe: this
	// capability's own archive step already sweeps it up as part of
	// mail.smtp-imap.v1's ordinary StateRoots entry, so this field exists
	// purely so restore knows where to write it back to.
	VMailRoot string
}
