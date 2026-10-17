package nginx

import (
	"errors"
	"fmt"
	"io/fs"
	"os"
	"os/user"
	"path/filepath"
	"regexp"
	"strconv"
)

// resourceIDPattern is the operation envelope's resource_id format (a UUID):
// checked again before a resource id becomes part of a path that is created,
// chowned or deleted.
var resourceIDPattern = regexp.MustCompile(`^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$`)

// ensureLogFiles creates a vhost's access and error logs before nginx opens
// them, owned by nginx's worker user (Config.LogOwner) and the log readers'
// group (Config.LogGroup), mode 0640, and puts existing ones right. nginx's
// workers reopen every log on USR1 (sent by log rotation every night), which
// they can only do on a file their own user may write; owned by root, the
// reopen fails with "Permission denied". Without a LogOwner (tests, a node
// without the user) the files are only created.
func ensureLogFiles(cfg Config, paths ...string) error {
	uid, gid := -1, -1

	if cfg.LogOwner != "" {
		if u, err := user.Lookup(cfg.LogOwner); err == nil {
			uid, _ = strconv.Atoi(u.Uid)
		}
	}

	if cfg.LogGroup != "" {
		if g, err := user.LookupGroup(cfg.LogGroup); err == nil {
			gid, _ = strconv.Atoi(g.Gid)
		}
	}

	for _, path := range paths {
		file, err := os.OpenFile(path, os.O_CREATE|os.O_APPEND|os.O_WRONLY, 0o640) //nolint:gosec // fixed log directory, resource-id named file
		if err != nil {
			return fmt.Errorf("creating log file %s: %w", path, err)
		}
		_ = file.Close()

		if uid == -1 && gid == -1 {
			continue
		}

		if err := os.Lchown(path, uid, gid); err != nil {
			return fmt.Errorf("setting the owner of log file %s: %w", path, err)
		}

		if err := os.Chmod(path, 0o640); err != nil {
			return fmt.Errorf("setting permissions on log file %s: %w", path, err)
		}
	}

	return nil
}

// RemoveDomainDirectory deletes a deleted web domain's own directory,
// AccountsRoot/<username>/domains/<resourceID> (its public/ docroot and
// anything the customer put there). Both inputs are re-validated, the
// directory must really be a directory (never a symlink a tenant could have
// planted), and os.RemoveAll never follows symlinks inside it. Missing is not
// an error. Shared with web.apache.v1, which serves the same directory.
func RemoveDomainDirectory(accountsRoot, username, resourceID string) error {
	if !docrootUsernamePattern.MatchString(username) || !resourceIDPattern.MatchString(resourceID) {
		return fmt.Errorf("refusing to remove the files of %q/%q: not a valid account user and resource id", username, resourceID)
	}

	dir := filepath.Join(accountsRoot, username, "domains", resourceID)

	info, err := os.Lstat(dir)
	if errors.Is(err, fs.ErrNotExist) {
		return nil
	}
	if err != nil {
		return fmt.Errorf("checking %s: %w", dir, err)
	}
	if !info.IsDir() {
		return fmt.Errorf("refusing to remove %s: not a directory", dir)
	}

	if err := os.RemoveAll(dir); err != nil {
		return fmt.Errorf("removing %s: %w", dir, err)
	}

	return nil
}

// RemoveResourceLogs deletes every log file of a resource in dir: the live
// <resourceID>.* files and their rotated copies.
func RemoveResourceLogs(dir, resourceID string) error {
	if !resourceIDPattern.MatchString(resourceID) {
		return fmt.Errorf("refusing to remove logs of %q: not a valid resource id", resourceID)
	}

	matches, err := filepath.Glob(filepath.Join(dir, resourceID+".*"))
	if err != nil {
		return err
	}

	for _, match := range matches {
		if err := os.Remove(match); err != nil && !errors.Is(err, fs.ErrNotExist) {
			return fmt.Errorf("removing %s: %w", match, err)
		}
	}

	return nil
}

// removeDeletedDomainData is what a successful delete leaves behind removed:
// the domain's directory, its access and error logs and its WAF audit logs.
// It reports what it could not remove instead of failing the delete, which
// has already taken effect.
func (c *NginxCapability) removeDeletedDomainData(username, resourceID string) []string {
	var problems []string

	if username != "" && c.cfg.AccountsRoot != "" {
		if err := RemoveDomainDirectory(c.cfg.AccountsRoot, username, resourceID); err != nil {
			problems = append(problems, err.Error())
		}
	}

	for _, dir := range []string{c.cfg.LogDir, c.cfg.WafLogDir} {
		if dir == "" {
			continue
		}

		if err := RemoveResourceLogs(dir, resourceID); err != nil {
			problems = append(problems, err.Error())
		}
	}

	return problems
}
