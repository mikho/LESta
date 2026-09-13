package backup

import (
	"bytes"
	"encoding/json"
	"fmt"
	"regexp"
)

// encryptionKeyPattern matches Backup::toProvisioningPayload()'s own
// plaintext encryption_key exactly as CreateBackup (Laravel) generates it:
// bin2hex(random_bytes(32)), a 64-character lowercase hex string decoding to
// exactly the 32 bytes AES-256 requires.
var encryptionKeyPattern = regexp.MustCompile(`^[0-9a-f]{64}$`)

// Payload is the backup.encrypted-artifacts.v1 capability's request body.
// Create and delete use disjoint fields (EncryptionKey vs. ArtifactPath, see
// Backup::toProvisioningPayload()'s own two-shape return on the Laravel
// side), so both are optional pointers here and ParseCreatePayload/
// ParseDeletePayload each enforce which one their own verb actually
// requires.
type Payload struct {
	Label         *string `json:"label,omitempty"`
	EncryptionKey *string `json:"encryption_key,omitempty"`
	ArtifactPath  *string `json:"artifact_path,omitempty"`
}

// ValidationError is a well-formed payload rejection: a schema-shaped (code,
// message, field) triple the caller turns directly into a rejected
// ResultEnvelope. It is never a Go error representing "no verdict was
// reached".
type ValidationError struct {
	Code    string
	Message string
	Field   string
}

func (e *ValidationError) Error() string {
	return fmt.Sprintf("%s: %s (field=%s)", e.Code, e.Message, e.Field)
}

// ParseCreatePayload decodes and validates raw for a create operation:
// EncryptionKey must be present and a real 64-character hex string.
func ParseCreatePayload(raw json.RawMessage) (Payload, error) {
	p, err := decode(raw)
	if err != nil {
		return Payload{}, err
	}

	if p.EncryptionKey == nil || !encryptionKeyPattern.MatchString(*p.EncryptionKey) {
		return Payload{}, &ValidationError{
			Code:    "invalid_encryption_key",
			Message: "encryption_key must be a 64-character lowercase hex string (a 32-byte AES-256 key)",
			Field:   "encryption_key",
		}
	}

	return p, nil
}

// ParseDeletePayload decodes and validates raw for a delete operation:
// ArtifactPath must be present and non-empty.
func ParseDeletePayload(raw json.RawMessage) (Payload, error) {
	return decodeWithArtifactPath(raw)
}

// ParseObservePayload decodes and validates raw for an observe operation:
// the same {artifact_path} shape as delete (see Backup::toProvisioningPayload()'s
// own no-key return branch, which both verbs dispatch with unchanged), since
// observe here means "read this artifact's own sealed bytes back," not
// drift detection the way bind9/mail's own observe implementations use it.
func ParseObservePayload(raw json.RawMessage) (Payload, error) {
	return decodeWithArtifactPath(raw)
}

// ParseRestorePayload decodes and validates raw for a restore operation:
// unlike every other verb here, restore needs BOTH fields at once -- the
// artifact to read (same as delete/observe) AND the key to decrypt it with
// (same as create) -- since decryption happens on this node, not in
// Laravel, the archive itself never having left local disk in the first
// place (see RestoreBackup.php's own doc comment on why).
func ParseRestorePayload(raw json.RawMessage) (Payload, error) {
	p, err := decodeWithArtifactPath(raw)
	if err != nil {
		return Payload{}, err
	}

	if p.EncryptionKey == nil || !encryptionKeyPattern.MatchString(*p.EncryptionKey) {
		return Payload{}, &ValidationError{
			Code:    "invalid_encryption_key",
			Message: "encryption_key must be a 64-character lowercase hex string (a 32-byte AES-256 key)",
			Field:   "encryption_key",
		}
	}

	return p, nil
}

func decodeWithArtifactPath(raw json.RawMessage) (Payload, error) {
	p, err := decode(raw)
	if err != nil {
		return Payload{}, err
	}

	if p.ArtifactPath == nil || *p.ArtifactPath == "" {
		return Payload{}, &ValidationError{
			Code:    "invalid_artifact_path",
			Message: "artifact_path must be a non-empty string",
			Field:   "artifact_path",
		}
	}

	return p, nil
}

func decode(raw json.RawMessage) (Payload, error) {
	var p Payload

	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&p); err != nil {
		return Payload{}, fmt.Errorf("decoding backup payload: %w", err)
	}

	return p, nil
}
