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
	// /var/lib/lesta/cron). Two fixed subpaths live under it:
	//
	//   - StateRoot/accounts/<run_as>/jobs/sidecar/<resource_id>.json: the
	//     JSON sidecar carrying the real command text, read only by the
	//     cron-run wrapper at execution time (running as run_as itself),
	//     never embedded in the crontab fragment itself. Per-account, not a
	//     single shared StateRoot/jobs/sidecar/ directory: see account.go's
	//     own ensureAccountDir doc comment for why StateRoot/accounts/
	//     <run_as> itself is owned root:<run_as> mode 2750 (setgid), never
	//     group-readable by the broader shared `lesta` group.
	//   - StateRoot/accounts/<run_as>/executions/<resource_id>.log: the
	//     cron-run wrapper's own capped execution log for that account (see
	//     runner.go), nested under the very same per-account owned root.
	//
	// StateRoot/jobs/<resource_id>/... (generation.Store's own idempotency/
	// observe bookkeeping: manifests, current/previous symlinks) is
	// deliberately NOT per-account: it is this node-local process's own
	// internal ledger, never read by a tenant's own identity, so it stays a
	// single shared directory namespaced away from StateRoot/accounts
	// exactly as it always has been, so neither writer's own file layout
	// can collide with the other's.
	StateRoot string
	// RunnerUser is the fixed, non-root system user the installer's own
	// synthetic self-test cron job runs as (production: "lesta-cron").
	// Since Phase 21, this is its ONLY use: every real tenant account's own
	// cron jobs instead run as that account's own dedicated, per-node Linux
	// system user (system.account-identity.v1, carried in each job's own
	// Payload.RunAs), never this shared identity. RunnerUser itself is
	// never read by this package's own code at all any more (renderFragment
	// uses payload.RunAs exclusively); it survives only as the literal
	// value .install/services/cron/install.sh's own self-test payload sets
	// Payload.RunAs to, so this field remains here purely as that literal's
	// documented, single source of truth for that one caller.
	RunnerUser string
	// AgentBinaryPath is the absolute path a crontab fragment's own
	// scheduled line invokes (production: /var/lib/lesta/agent/bin/
	// lesta-agent), via "<AgentBinaryPath> cron-run <resource_id>". Never
	// configurable at runtime beyond this struct: see
	// agent/cmd/lesta-agent/main.go's own cronProductionConfig for why this
	// is a fixed production literal, not an environment override.
	AgentBinaryPath string
}
