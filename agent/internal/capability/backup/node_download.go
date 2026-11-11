package backup

import (
	"context"
	"encoding/json"
	"errors"
	"net/url"
	"os"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// applyNodeDownload decrypts the whole-node backup here and streams it to the control plane's one-time
// upload address as a plain tar.gz, in chunks, so a large backup never has to fit in one result. The
// control plane does not hold the plaintext until the whole sealed archive has authenticated: the final
// chunk is only sent then. Without an upload address, applyObserve still returns the sealed artifact
// itself (base64, size-capped) for a control plane that asks the old way.
func (c *BackupCapability) applyNodeDownload(ctx context.Context, op protocol.OperationEnvelope, payload Payload, artifact string) (protocol.ResultEnvelope, error) {
	if payload.EncryptionKey == nil || !encryptionKeyPattern.MatchString(*payload.EncryptionKey) {
		return c.rejected(op, "invalid_encryption_key", "encryption_key must be a 64-character lowercase hex string", "encryption_key")
	}

	token := derefString(payload.UploadToken)
	if token != "" && !bearerTokenPattern.MatchString(token) {
		return c.rejected(op, "invalid_upload_url", "the upload token is not valid", "upload_token")
	}

	target, err := url.Parse(*payload.UploadURL)
	if err != nil || target.Scheme != "https" || target.Host == "" || target.RawQuery != "" || !uploadPathPattern.MatchString(target.Path) {
		return c.rejected(op, "invalid_upload_url", "the upload address is not a one-time backup upload address", "upload_url")
	}

	plain, closeArtifact, err := readableNodeArtifact(artifact, *payload.EncryptionKey, maxNodeArchiveBytes)
	if errors.Is(err, os.ErrNotExist) {
		return c.rejected(op, "artifact_not_found", "no artifact exists at the given path", "artifact_path")
	} else if errors.Is(err, errNodeBackupTooLarge) {
		return c.failed(op, "artifact_too_large", err.Error())
	} else if err != nil {
		return c.failed(op, "artifact_read_failed", err.Error())
	}

	defer closeArtifact()

	verified, err := verifiedReader(plain)
	if err != nil {
		return c.failed(op, "decryption_failed", err.Error())
	}

	sent, readErr, uploadErr := uploadStream(ctx, c.cfg.uploadClient(), target, token, verified)
	if readErr != nil {
		return c.failed(op, "decryption_failed", readErr.Error())
	}

	if uploadErr != nil {
		return c.failed(op, "upload_failed", uploadErr.Error())
	}

	data, err := json.Marshal(accountDownloadData{BytesSent: sent})
	if err != nil {
		panic("marshaling backup download data: " + err.Error())
	}

	result := c.buildResult(op, protocol.StatusApplied, nil)
	result.Data = data

	return result, nil
}
