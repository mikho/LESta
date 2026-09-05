package identity

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
)

// zeroDigest is the fixed placeholder observed_state_digest used only when a
// fresh live check cannot even be attempted (a payload that doesn't decode
// to a usable username at all, e.g. a malformed envelope's very first
// rejection).
const zeroDigest = "sha256:0000000000000000000000000000000000000000000000000000000000000000"

// computeDigest fingerprints the one bit of live state this capability ever
// manages: whether username currently exists as a system user on this node.
// Unlike nginx/apache/bind9/acme's own recursive-directory-manifest digests,
// there is no rendered file tree to hash here; the "id -u" check itself IS
// the live-state read, mirroring mariadb's own computeDigest querying the
// live server rather than a local file.
func computeDigest(ctx context.Context, cfg Config, username string) (string, error) {
	exists, err := userExists(ctx, cfg, username)
	if err != nil {
		return "", err
	}

	state := "absent"
	if exists {
		state = "present"
	}

	sum := sha256.Sum256([]byte("system-user:" + username + ":" + state))

	return "sha256:" + hex.EncodeToString(sum[:]), nil
}
