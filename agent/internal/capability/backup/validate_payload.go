package backup

import (
	"encoding/json"
	"fmt"
	"net/url"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// ValidatePayload runs every check this capability makes on an operation's payload before it
// touches the system, for each shape the operation can take (whole node or one account, a plain
// observe or a download, a backup or an import). It leaves out what depends on the node: whether
// artifact_path lies inside the artifacts root and whether the artifact exists. The operation
// signer calls it before signing; Apply and the root helper make the same checks.
//
// A payload that does not decode is returned as the decoding error, not a *ValidationError,
// because Apply reports it as an error without a verdict too.
func ValidatePayload(operation protocol.Operation, resourceID string, raw json.RawMessage) error {
	switch operation {
	case protocol.OperationCreate:
		p, err := ParseCreatePayload(raw)
		if err != nil {
			return err
		}

		if p.Account != nil && (p.SourceURL != nil || p.ObjectKey != nil) {
			return validateImportSource(p)
		}

		return nil
	case protocol.OperationDelete:
		_, err := ParseDeletePayload(raw)

		return err
	case protocol.OperationObserve:
		p, err := ParseObservePayload(raw)
		if err != nil {
			return err
		}

		if p.Account != nil {
			return validateAccountDownload(p)
		}

		if p.UploadURL != nil && *p.UploadURL != "" {
			return validateNodeDownload(p)
		}

		return nil
	case protocol.OperationRestore:
		_, err := ParseRestorePayload(raw)

		return err
	case protocol.OperationUpdate:
		return validateAccountCopy(raw)
	default:
		return &ValidationError{
			Code:    "unsupported_operation",
			Message: fmt.Sprintf("operation %q is not supported; backup.encrypted-artifacts.v1 only implements create, delete, observe, restore and (per-account) update", operation),
		}
	}
}

// validateImportSource is applyAccountImportOp's check plus the root helper's (fetchImport).
func validateImportSource(p Payload) error {
	if p.SourceURL != nil && p.ObjectKey != nil {
		return &ValidationError{Code: "invalid_import_source", Message: "an import names one source: an address or an object in storage", Field: "source_url"}
	}

	if p.SourceURL == nil || *p.SourceURL == "" {
		return validateS3(p.Destination, derefString(p.ObjectKey))
	}

	target, err := url.Parse(*p.SourceURL)
	if err != nil || target.Scheme != "https" || target.Host == "" || target.RawQuery != "" || !importPathPattern.MatchString(target.Path) {
		return &ValidationError{Code: "import_fetch_failed", Message: "the source address is not a one-time backup import address", Field: "source_url"}
	}

	if token := derefString(p.SourceToken); token != "" && !bearerTokenPattern.MatchString(token) {
		return &ValidationError{Code: "import_fetch_failed", Message: "the source token is not valid", Field: "source_token"}
	}

	return nil
}

// validateAccountDownload is applyAccountDownloadOp's check plus the root helper's
// (applyAccountDownload).
func validateAccountDownload(p Payload) error {
	if p.EncryptionKey == nil || !encryptionKeyPattern.MatchString(*p.EncryptionKey) {
		return &ValidationError{Code: "invalid_encryption_key", Message: "encryption_key must be a 64-character lowercase hex string", Field: "encryption_key"}
	}

	if p.UploadURL == nil || *p.UploadURL == "" {
		return &ValidationError{Code: "invalid_upload_url", Message: "upload_url is required for a download", Field: "upload_url"}
	}

	if err := validateAccountPayload(p.Account); err != nil {
		return err
	}

	return validateUploadTarget(p)
}

// validateNodeDownload is applyNodeDownload's check.
func validateNodeDownload(p Payload) error {
	if p.EncryptionKey == nil || !encryptionKeyPattern.MatchString(*p.EncryptionKey) {
		return &ValidationError{Code: "invalid_encryption_key", Message: "encryption_key must be a 64-character lowercase hex string", Field: "encryption_key"}
	}

	return validateUploadTarget(p)
}

func validateUploadTarget(p Payload) error {
	if token := derefString(p.UploadToken); token != "" && !bearerTokenPattern.MatchString(token) {
		return &ValidationError{Code: "invalid_upload_url", Message: "the upload token is not valid", Field: "upload_token"}
	}

	target, err := url.Parse(*p.UploadURL)
	if err != nil || target.Scheme != "https" || target.Host == "" || target.RawQuery != "" || !uploadPathPattern.MatchString(target.Path) {
		return &ValidationError{Code: "invalid_upload_url", Message: "the upload address is not a one-time backup upload address", Field: "upload_url"}
	}

	return nil
}

// validateAccountCopy is applyAccountCopyOp's check: update only copies a per-account backup to
// the account's own storage.
func validateAccountCopy(raw json.RawMessage) error {
	p, err := decode(raw)
	if err != nil {
		return err
	}

	if p.Account == nil || p.Destination == nil || p.ObjectKey == nil || p.ArtifactPath == nil || p.EncryptionKey == nil {
		return &ValidationError{Code: "unsupported_operation", Message: "update is only supported for copying a per-account backup to its storage"}
	}

	if !encryptionKeyPattern.MatchString(*p.EncryptionKey) {
		return &ValidationError{Code: "invalid_encryption_key", Message: "encryption_key must be a 64-character lowercase hex string", Field: "encryption_key"}
	}

	if err := validateAccountPayload(p.Account); err != nil {
		return err
	}

	return validateS3(p.Destination, *p.ObjectKey)
}
