package backup

import (
	"crypto/sha256"
	"encoding/hex"
)

// checksumOf fingerprints an artifact's own sealed (encrypted) bytes exactly
// as written to disk, matching BackupFactory.php's own 'sha256:'.hash(...)
// format.
func checksumOf(sealed []byte) string {
	sum := sha256.Sum256(sealed)

	return "sha256:" + hex.EncodeToString(sum[:])
}
