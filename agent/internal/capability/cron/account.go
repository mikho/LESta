package cron

import (
	"fmt"
	"os"
	"os/user"
	"path/filepath"
	"strconv"
)

// ensureAccountDir makes sure StateRoot/accounts/<runAs> exists, owned
// root:<runAs> (relying on system.account-identity.v1's own useradd
// invocation having already created a same-named primary group for runAs;
// see internal/capability/identity's own createSystemUser doc comment),
// mode 2750 (setgid, rwxr-x---): traversable and readable only by root and
// by members of runAs's own primary group, i.e. runAs's own dedicated Linux
// user alone, never the broader shared `lesta` group every other owned
// root under StateRoot uses.
//
// Idempotent: safe to call on every write, not just the first one for a
// given account, since MkdirAll/Chown/Chmod are all themselves idempotent.
// The setgid bit means every file/subdirectory later created inside this
// directory (jobs/sidecar/<id>.json, executions/<id>.log, and their own
// parent directories) inherits its group ownership automatically, without
// this function -- or capability.go's own writeFileAtomic -- needing to
// chown each of them individually.
func ensureAccountDir(stateRoot, runAs string) error {
	dir := accountDirFor(stateRoot, runAs)

	if err := os.MkdirAll(dir, 0o751); err != nil {
		return fmt.Errorf("creating account directory %s: %w", dir, err)
	}

	grp, err := user.LookupGroup(runAs)
	if err != nil {
		return fmt.Errorf("looking up %s's own primary group (expected to already exist, created by system.account-identity.v1's own useradd invocation): %w", runAs, err)
	}

	gid, err := strconv.Atoi(grp.Gid)
	if err != nil {
		return fmt.Errorf("parsing gid %q for group %s: %w", grp.Gid, runAs, err)
	}

	// uid -1 ("leave unchanged"), never an explicit 0: this directory is
	// always created immediately above by this very process, which is
	// already root in production (the agent's own OperationEnvelope
	// pipeline never runs as anything else), so its owner is already root
	// by construction. Only the GROUP needs changing here, from whatever
	// this process's own default group is to runAs's own primary group --
	// exactly the one chown POSIX permits even an unprivileged owner to
	// perform (provided the caller is a member of the target group),
	// unlike changing the owner itself, which always requires root
	// regardless of group membership.
	if err := os.Chown(dir, -1, gid); err != nil {
		return fmt.Errorf("chowning %s to root:%s: %w", dir, runAs, err)
	}

	if err := os.Chmod(dir, os.ModeSetgid|0o750); err != nil {
		return fmt.Errorf("chmodding %s to setgid 2750: %w", dir, err)
	}

	return nil
}

// accountDirFor returns StateRoot/accounts/<runAs>, this account's own
// owned root under a shared StateRoot. A free function (rather than a
// *CronCapability method) so runner.go's own package-level RunJob, which
// has no CronCapability instance of its own, can build the identical path.
func accountDirFor(stateRoot, runAs string) string {
	return filepath.Join(stateRoot, "accounts", runAs)
}
