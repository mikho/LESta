package mail

import (
	"errors"
	"fmt"
	"io/fs"
	"os"
	"path/filepath"
)

// removeMaildir deletes one mailbox's mail, VmailRoot/<domain>/<localPart>,
// or with an empty localPart the whole domain's, VmailRoot/<domain>. Both are
// re-validated against the payload patterns, the target must be a directory
// (never a symlink), and os.RemoveAll never follows symlinks inside it.
// Missing is not an error; without a VmailRoot nothing is removed.
func (c *MailCapability) removeMaildir(domain, localPart string) error {
	if c.cfg.VmailRoot == "" {
		return nil
	}

	if !domainPattern.MatchString(domain) || (localPart != "" && !localPartPattern.MatchString(localPart)) {
		return fmt.Errorf("refusing to remove the mail of %q@%q: not a valid address", localPart, domain)
	}

	dir := filepath.Join(c.cfg.VmailRoot, domain)
	if localPart != "" {
		dir = filepath.Join(dir, localPart)
	}

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

// removedMailboxes is the local parts previous had and current no longer has:
// mailboxes deleted from the domain. Taken from the previous generation, never
// from what is on disk, so a mailbox is only ever removed because the panel
// removed it.
func removedMailboxes(previous, current Payload) []string {
	kept := make(map[string]bool, len(current.Accounts))
	for _, account := range current.Accounts {
		kept[account.LocalPart] = true
	}

	var removed []string
	for _, account := range previous.Accounts {
		if !kept[account.LocalPart] {
			removed = append(removed, account.LocalPart)
		}
	}

	return removed
}
