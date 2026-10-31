package cron

import (
	"fmt"
	"os"
	"os/user"
	"path/filepath"
	"strconv"
	"strings"
)

// ensureAccountDir makes sure StateRoot/accounts/<runAs> exists, owned
// root:<runAs> (relying on system.account-identity.v1's own useradd
// invocation having already created a same-named primary group for runAs;
// see internal/capability/identity's own createSystemUser doc comment),
// mode 0750 (rwxr-x---): traversable and readable only by root and
// by members of runAs's own primary group, i.e. runAs's own dedicated Linux
// user alone, never the broader shared `lesta` group every other owned
// root under StateRoot uses.
//
// Idempotent: safe to call on every write, not just the first one for a
// given account, since MkdirAll/Chown/Chmod are all themselves idempotent.
// What is written inside it gets runAs's group from writeSidecar, not from a
// setgid bit: the applier lacks CAP_FSETID, so the kernel drops that bit.
func ensureAccountDir(cfg Config, runAs string) error {
	dir := accountDirFor(cfg.StateRoot, runAs)

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

	if err := os.Chmod(dir, 0o750); err != nil {
		return fmt.Errorf("chmodding %s to 0750: %w", dir, err)
	}

	return ensureExecutionsDir(cfg, runAs)
}

// ensureExecutionsDir makes sure StateRoot/executions/<runAs> exists, owned
// <runAs>:<ExecutionsGroup>, mode 0750. The job runs as runAs and appends its
// log there, readable by all (0644, see appendExecutionLog), which only the
// owner and ExecutionsGroup (the unprivileged daemon) can reach through this
// directory; no other tenant can enter it. No setgid: the applier lacks
// CAP_FSETID, so the kernel would silently drop it. StateRoot/executions itself
// is root:<ExecutionsGroup> 0751, so the daemon can list the accounts and each
// account can only traverse to its own directory.
func ensureExecutionsDir(cfg Config, runAs string) error {
	grp, err := user.LookupGroup(cfg.ExecutionsGroup)
	if err != nil {
		return fmt.Errorf("looking up the execution log group %s: %w", cfg.ExecutionsGroup, err)
	}

	gid, err := strconv.Atoi(grp.Gid)
	if err != nil {
		return fmt.Errorf("parsing gid %q for group %s: %w", grp.Gid, cfg.ExecutionsGroup, err)
	}

	owner, err := user.Lookup(runAs)
	if err != nil {
		return fmt.Errorf("looking up user %s: %w", runAs, err)
	}

	uid, err := strconv.Atoi(owner.Uid)
	if err != nil {
		return fmt.Errorf("parsing uid %q for user %s: %w", owner.Uid, runAs, err)
	}

	root := filepath.Join(cfg.StateRoot, "executions")
	if err := os.MkdirAll(root, 0o751); err != nil {
		return fmt.Errorf("creating %s: %w", root, err)
	}

	if err := os.Chown(root, -1, gid); err != nil {
		return fmt.Errorf("chowning %s to group %s: %w", root, cfg.ExecutionsGroup, err)
	}

	if err := os.Chmod(root, 0o751); err != nil {
		return fmt.Errorf("chmodding %s to 0751: %w", root, err)
	}

	dir := ExecutionsDirFor(cfg.StateRoot, runAs)
	if err := os.Mkdir(dir, 0o750); err != nil && !os.IsExist(err) {
		return fmt.Errorf("creating %s: %w", dir, err)
	}

	if err := os.Lchown(dir, uid, gid); err != nil {
		return fmt.Errorf("chowning %s to %s:%s: %w", dir, runAs, cfg.ExecutionsGroup, err)
	}

	if err := os.Chmod(dir, 0o750); err != nil {
		return fmt.Errorf("chmodding %s to 0750: %w", dir, err)
	}

	return nil
}

// ExecutionsDirFor returns StateRoot/executions/<runAs>, where runAs's jobs
// append their execution logs and the daemon reads them.
func ExecutionsDirFor(stateRoot, runAs string) string {
	return filepath.Join(stateRoot, "executions", runAs)
}

// accountDirFor returns StateRoot/accounts/<runAs>, this account's own
// owned root under a shared StateRoot. A free function (rather than a
// *CronCapability method) so runner.go's own package-level RunJob, which
// has no CronCapability instance of its own, can build the identical path.
func accountDirFor(stateRoot, runAs string) string {
	return filepath.Join(stateRoot, "accounts", runAs)
}

// writeSidecar writes resourceID's sidecar 0640, readable by runAs's own
// group, which is the job's identity when cron runs it. The group is set on
// the file (before it replaces the old one) and on every folder created above
// it: the setgid bit on the account folder no longer passes it down, since the
// applier lacks CAP_FSETID and the kernel silently drops that bit.
func writeSidecar(stateRoot, runAs, resourceID string, content []byte) error {
	grp, err := user.LookupGroup(runAs)
	if err != nil {
		return fmt.Errorf("looking up %s's own primary group: %w", runAs, err)
	}

	gid, err := strconv.Atoi(grp.Gid)
	if err != nil {
		return fmt.Errorf("parsing gid %q for group %s: %w", grp.Gid, runAs, err)
	}

	path := sidecarPathFor(stateRoot, runAs, resourceID)
	accountDir := accountDirFor(stateRoot, runAs)

	if err := os.MkdirAll(filepath.Dir(path), 0o750); err != nil {
		return fmt.Errorf("creating directory for %s: %w", path, err)
	}

	for dir := filepath.Dir(path); dir != accountDir && strings.HasPrefix(dir, accountDir); dir = filepath.Dir(dir) {
		if err := os.Chown(dir, -1, gid); err != nil {
			return fmt.Errorf("giving %s to group %s: %w", dir, runAs, err)
		}
	}

	tmp := path + ".tmp"
	if err := os.WriteFile(tmp, content, 0o640); err != nil {
		return fmt.Errorf("writing staged file for %s: %w", path, err)
	}

	if err := os.Chown(tmp, -1, gid); err != nil {
		_ = os.Remove(tmp)

		return fmt.Errorf("giving %s to group %s: %w", tmp, runAs, err)
	}

	if err := os.Rename(tmp, path); err != nil {
		return fmt.Errorf("activating %s: %w", path, err)
	}

	return nil
}
