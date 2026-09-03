package cron

// Config parameterizes CronCapability by the fixed paths and identities this
// capability needs, so the identical implementation runs against
// production's real /etc/cron.d and /var/lib/lesta/cron or a fully
// disposable per-test temp directory.
type Config struct {
	// FragmentDir is the owned root every crontab fragment is written into
	// (production: /etc/cron.d). One file per resource, named
	// "lesta-<resource_id>".
	FragmentDir string
	// StateRoot is this capability's own bookkeeping root (production:
	// /var/lib/lesta/cron). Three fixed subpaths live under it:
	//
	//   - StateRoot/jobs/sidecar/<resource_id>.json: the JSON sidecar
	//     carrying the real command text, read only by the cron-run wrapper
	//     at execution time, never embedded in the crontab fragment itself.
	//   - StateRoot/jobs/<resource_id>/...: generation.Store's own
	//     idempotency/observe bookkeeping (manifests, current/previous
	//     symlinks), namespaced away from the sidecar files above so
	//     neither writer's own file layout can collide with the other's.
	//   - StateRoot/executions/<resource_id>.log: the cron-run wrapper's
	//     own capped execution log (see runner.go).
	StateRoot string
	// RunnerUser is the fixed, non-root system user every crontab fragment
	// names in its own user-column (production: "lesta-cron"). Every
	// account's cron commands run as this same shared identity on a given
	// node: a disclosed limitation, not OS-level isolation between tenants.
	RunnerUser string
	// AgentBinaryPath is the absolute path a crontab fragment's own
	// scheduled line invokes (production: /var/lib/lesta/agent/bin/
	// lesta-agent), via "<AgentBinaryPath> cron-run <resource_id>". Never
	// configurable at runtime beyond this struct: see
	// agent/cmd/lesta-agent/main.go's own cronProductionConfig for why this
	// is a fixed production literal, not an environment override.
	AgentBinaryPath string
}
