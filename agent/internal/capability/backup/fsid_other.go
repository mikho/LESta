//go:build !linux

package backup

// runAsAccount has no filesystem-identity switch off Linux (the agent only runs
// on Linux nodes); it exists so the package builds and tests on a developer
// machine, where the hook is not used.
func runAsAccount(_, _ int, fn func() error) error {
	return fn()
}
