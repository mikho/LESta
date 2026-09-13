package metrics

import (
	"io/fs"
	"os"
	"path/filepath"
)

// mailboxDiskBytes returns the real, recursive total size of every regular
// file under VmailRoot/<domain>/<local_part> (Dovecot's own maildir layout:
// cur/, new/, tmp/ subdirectories, one file per message). A mailbox that
// does not exist yet (no mail ever delivered) reports 0, not an error --
// this is a normal, expected state for a freshly created account, mirroring
// every other capability's own "absent means zero/empty, not a fault"
// convention.
func mailboxDiskBytes(vmailRoot, domain, localPart string) (int64, error) {
	root := filepath.Join(vmailRoot, domain, localPart)

	if _, err := os.Stat(root); err != nil {
		if os.IsNotExist(err) {
			return 0, nil
		}

		return 0, err
	}

	var total int64

	err := filepath.WalkDir(root, func(_ string, d fs.DirEntry, err error) error {
		if err != nil {
			return err
		}

		if d.IsDir() {
			return nil
		}

		info, err := d.Info()
		if err != nil {
			return err
		}

		total += info.Size()

		return nil
	})
	if err != nil {
		return 0, err
	}

	return total, nil
}
