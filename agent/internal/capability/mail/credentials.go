package mail

import (
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
)

// credentialsFileName is a durable address -> Dovecot-hash archive, distinct
// from the rendered live passwd file (DovecotPasswdPath). This distinction
// is load-bearing, not incidental: the rendered file structurally OMITS a
// suspended account (the same "suspended = absent from rendered live
// config" idiom every other capability uses), but Laravel's own
// MailDomain::toProvisioningPayload() never re-supplies a password on
// suspend/unsuspend (matching the ADR's "credentials are never included in
// normal desired-state payloads" restriction, identical to every other
// password-bearing resource in this project) -- so the moment an account is
// first suspended, its hash would be gone forever with nothing left to
// reconstruct it from on a later unsuspend, if the rendered file were the
// only place it ever lived. This archive is the one place a hash survives
// a suspend/unsuspend cycle untouched.
const credentialsFileName = "dovecot-credentials.json"

func (c *MailCapability) credentialsPath() string {
	return filepath.Join(c.cfg.StateRoot, credentialsFileName)
}

// loadCredentials reads the durable address -> hash archive, returning an
// empty map (never an error) if it doesn't exist yet.
func (c *MailCapability) loadCredentials() (map[string]string, error) {
	raw, err := os.ReadFile(c.credentialsPath())
	if err != nil {
		if os.IsNotExist(err) {
			return map[string]string{}, nil
		}

		return nil, fmt.Errorf("reading credentials archive: %w", err)
	}

	var hashes map[string]string
	if err := json.Unmarshal(raw, &hashes); err != nil {
		return nil, fmt.Errorf("parsing credentials archive: %w", err)
	}

	return hashes, nil
}

// saveCredentials atomically rewrites the durable archive with hashes in
// full (never a partial merge from the caller's side: render's own caller
// always starts from loadCredentials' own result and only ever adds to it,
// so passing the accumulated map back here is already the correct full
// state). A plain rename-into-place, mirroring every other atomic-write
// path in this module; StateRoot is this capability's own private root, so
// this file is never read by Exim or Dovecot directly, only by this
// capability's own future runs.
func (c *MailCapability) saveCredentials(hashes map[string]string) error {
	raw, err := json.Marshal(hashes)
	if err != nil {
		return fmt.Errorf("encoding credentials archive: %w", err)
	}

	path := c.credentialsPath()
	staging := path + stagingSuffix

	if err := os.MkdirAll(filepath.Dir(path), 0o700); err != nil {
		return fmt.Errorf("creating credentials archive directory: %w", err)
	}

	if err := os.WriteFile(staging, raw, 0o600); err != nil {
		return fmt.Errorf("writing staged credentials archive: %w", err)
	}

	if err := os.Rename(staging, path); err != nil {
		return fmt.Errorf("activating credentials archive: %w", err)
	}

	return nil
}
