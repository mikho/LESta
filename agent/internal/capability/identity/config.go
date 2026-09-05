package identity

// Config parameterizes IdentityCapability by the three external binaries it
// execs, so the identical implementation runs against a real host's own
// useradd/userdel/id or a disposable fake on PATH in a test harness.
// Mirrors mariadb.Config's own *Binary field pattern: every field here is
// passed straight into an exec.Command call (see capability.go), so, exactly
// like every other capability's own *Binary field, it is fixed at process
// start (cmd/lesta-agent/main.go's own identityProductionConfig), never
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
