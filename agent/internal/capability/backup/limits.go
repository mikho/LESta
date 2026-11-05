package backup

import (
	"bytes"
	"errors"
	"fmt"
	"os"
)

// The whole-node backup is built, sealed and restored in memory (see archiveStateRoots), so every
// buffer on that path has a hard cap. A node whose state, database dumps or mailboxes outgrow it
// gets a clear failure instead of an out-of-memory kill of the process that applies every other
// operation; mailbox content belongs in the per-account backups, which stream.
const (
	// maxNodeArchiveBytes caps the compressed archive, the sealed artifact on disk and any dump.
	maxNodeArchiveBytes = 1 << 30
	// maxNodeRestoredBytes caps what a restore keeps in memory after decompression.
	maxNodeRestoredBytes = 2 << 30
	// maxNodeDownloadBytes caps the artifact an observe returns inside one result (base64 inflates it by a third).
	maxNodeDownloadBytes = 256 << 20
)

var errNodeBackupTooLarge = errors.New("the whole-node backup is too large to handle in memory")

// limitedBuffer is a bytes.Buffer that refuses to grow past max.
type limitedBuffer struct {
	bytes.Buffer
	max int
}

func (b *limitedBuffer) Write(p []byte) (int, error) {
	if b.Len()+len(p) > b.max {
		return 0, fmt.Errorf("%w (over %d MiB); back up mailboxes with the per-account backups", errNodeBackupTooLarge, b.max>>20)
	}

	return b.Buffer.Write(p)
}

// restoreBudget counts the bytes a restore holds in memory.
type restoreBudget struct {
	left int64
}

func newRestoreBudget() *restoreBudget {
	return &restoreBudget{left: maxNodeRestoredBytes}
}

// take reserves n bytes, or reports the backup as too large.
func (b *restoreBudget) take(n int64) error {
	if n < 0 || n > b.left {
		return fmt.Errorf("%w (over %d MiB restored)", errNodeBackupTooLarge, maxNodeRestoredBytes>>20)
	}

	b.left -= n

	return nil
}

// checkArtifactSize refuses an artifact file bigger than limit before it is read into memory.
func checkArtifactSize(path string, limit int64) error {
	info, err := os.Stat(path)
	if err != nil {
		return err
	}

	if info.Size() > limit {
		return fmt.Errorf("%w: %d MiB on disk, limit %d MiB", errNodeBackupTooLarge, info.Size()>>20, limit>>20)
	}

	return nil
}
