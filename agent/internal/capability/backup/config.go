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
	StateRoots map[string]string
}
