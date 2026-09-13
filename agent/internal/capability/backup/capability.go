// Package backup implements the backup.encrypted-artifacts.v1 capability: a
// real, encrypted, whole-node snapshot of whichever other capabilities'
// state roots are actually present on this node (see Config.StateRoots),
// written to a fixed, owned artifact directory (Config.ArtifactsRoot).
// Provider-admin-only, node-scoped, local-disk-only for this v1 pass (see
// the Backups design decision, vault): there is no S3-compatible or other
// remote storage backend yet, and no per-account/tenant scoping, since a
// real artifact today commingles every account hosted on the node.
//
// Only create and delete do real work, mirroring internal/capability/
// identity's own "no update/suspend/unsuspend/observe" precedent: a backup
// is either created outright or removed outright, never mutated in between
// (Laravel's own BackupPolicy has no update/suspend/unsuspend ability
// either).
//
// Unlike every rendered-config capability (nginx/bind9/apache/mail), this
// capability never renders a template and never reloads a service: its own
// "apply" IS the real work (archive, encrypt, write, checksum), not a
// render-then-activate generation swap, so it keeps no generation.Store
// history either -- there is exactly one artifact per Create operation, and
// Delete removes exactly that one artifact. Idempotent replay of a Create
// whose on-disk artifact already exists is handled by checking the
// filesystem directly (see applyCreate), not by relying on the in-process
// idempotency.Store surviving across calls -- it doesn't, for the same
// reason documented on that package: this agent process, and the capability
// instance dispatchOperation constructs for a given call, has no guaranteed
// lifetime across separate dispatch invocations.
package backup

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"time"

	"github.com/mikho/LESta/agent/internal/idempotency"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// zeroDigest is the fixed placeholder observed_state_digest used whenever a
// result carries no real artifact to fingerprint (a rejection, a failure, or
// a delete's own removal).
const zeroDigest = "sha256:0000000000000000000000000000000000000000000000000000000000000000"

// BackupCapability implements protocol.Capability for
// backup.encrypted-artifacts.v1.
type BackupCapability struct {
	cfg      Config
	receipts *idempotency.Store
}

// New returns a BackupCapability using cfg.
func New(cfg Config) *BackupCapability {
	return &BackupCapability{
		cfg:      cfg,
		receipts: idempotency.New(),
	}
}

// artifactData is the shape reported on a successful create's own
// ResultEnvelope.Data, matching RecordsBackupArtifact.php's own expected
// keys exactly.
type artifactData struct {
	IncludedCapabilities []string `json:"included_capabilities"`
	SizeBytes            int64    `json:"size_bytes"`
	Checksum             string   `json:"checksum"`
	ArtifactPath         string   `json:"artifact_path"`
}

// Apply implements protocol.Capability.
func (c *BackupCapability) Apply(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	ctx, cancel := context.WithDeadline(ctx, op.Deadline)
	defer cancel()

	if prior, ok := c.receipts.Lookup(op.IdempotencyKey); ok {
		if prior.Status == protocol.StatusApplied || prior.Status == protocol.StatusAlreadyApplied {
			prior.Status = protocol.StatusAlreadyApplied
		}

		return prior, nil
	}

	var (
		result protocol.ResultEnvelope
		err    error
	)

	switch op.Operation {
	case protocol.OperationCreate:
		result, err = c.applyCreate(ctx, op)
	case protocol.OperationDelete:
		result, err = c.applyDelete(op)
	default:
		result, err = c.rejected(op, "unsupported_operation",
			fmt.Sprintf("operation %q is not supported; backup.encrypted-artifacts.v1 only implements create and delete", op.Operation), "")
	}

	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	c.receipts.Record(op.IdempotencyKey, result)

	return result, nil
}

// applyCreate archives, encrypts, and writes a fresh artifact for op's
// resource_id, unless one already exists on disk from a prior successful
// apply of the same resource_id (an idempotent replay: recompute its own
// checksum/size rather than redoing the real archive+encrypt work).
func (c *BackupCapability) applyCreate(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParseCreatePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(op, verr)
	}

	included := discoverIncludedCapabilities(c.cfg.StateRoots)
	artifactPath := filepath.Join(c.cfg.ArtifactsRoot, op.ResourceID+".tar.enc")

	if sealed, err := os.ReadFile(artifactPath); err == nil {
		return c.applied(op, protocol.StatusAlreadyApplied, artifactPath, sealed, included), nil
	}

	if ctx.Err() != nil {
		return protocol.ResultEnvelope{}, ctx.Err()
	}

	plaintext, err := archiveStateRoots(c.cfg.StateRoots, included)
	if err != nil {
		return c.failed(op, "archive_failed", err.Error())
	}

	sealed, err := encrypt(*payload.EncryptionKey, plaintext)
	if err != nil {
		return c.failed(op, "encryption_failed", err.Error())
	}

	if err := os.MkdirAll(c.cfg.ArtifactsRoot, 0o750); err != nil {
		return c.failed(op, "artifacts_root_unavailable", err.Error())
	}

	if err := os.WriteFile(artifactPath, sealed, 0o600); err != nil {
		return c.failed(op, "artifact_write_failed", err.Error())
	}

	return c.applied(op, protocol.StatusApplied, artifactPath, sealed, included), nil
}

// applyDelete removes the artifact at payload.ArtifactPath, which must
// resolve within Config.ArtifactsRoot. Laravel only ever sends back a path
// this same capability itself wrote (Backup::artifact_path, populated from
// this capability's own prior create response), but this capability never
// trusts that invariant blindly, mirroring internal/capability/identity's
// own defense-in-depth discipline against tenant-influenced input.
func (c *BackupCapability) applyDelete(op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParseDeletePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(op, verr)
	}

	cleaned := filepath.Clean(*payload.ArtifactPath)
	if !isWithinRoot(cleaned, c.cfg.ArtifactsRoot) {
		return c.rejected(op, "artifact_path_outside_root",
			fmt.Sprintf("artifact_path %q is not within the owned artifacts root %q", *payload.ArtifactPath, c.cfg.ArtifactsRoot), "artifact_path")
	}

	if _, err := os.Stat(cleaned); errors.Is(err, os.ErrNotExist) {
		return c.buildResult(op, protocol.StatusAlreadyApplied, nil), nil
	} else if err != nil {
		return c.failed(op, "artifact_stat_failed", err.Error())
	}

	if err := os.Remove(cleaned); err != nil {
		return c.failed(op, "artifact_remove_failed", err.Error())
	}

	return c.buildResult(op, protocol.StatusApplied, nil), nil
}

func isWithinRoot(path, root string) bool {
	rel, err := filepath.Rel(root, path)
	if err != nil {
		return false
	}

	return rel != ".." && !strings.HasPrefix(rel, ".."+string(filepath.Separator))
}

func (c *BackupCapability) rejectedFromValidationError(op protocol.OperationEnvelope, err error) (protocol.ResultEnvelope, error) {
	var ve *ValidationError
	if errors.As(err, &ve) {
		return c.rejected(op, ve.Code, ve.Message, ve.Field)
	}

	return protocol.ResultEnvelope{}, err
}

func (c *BackupCapability) rejected(op protocol.OperationEnvelope, code, message, field string) (protocol.ResultEnvelope, error) {
	var fieldPtr *string
	if field != "" {
		fieldPtr = &field
	}

	errs := []protocol.ResultError{{Code: code, Message: message, Field: fieldPtr}}

	return c.buildResult(op, protocol.StatusRejected, errs), nil
}

func (c *BackupCapability) failed(op protocol.OperationEnvelope, code, message string) (protocol.ResultEnvelope, error) {
	return c.buildResult(op, protocol.StatusFailed, []protocol.ResultError{{Code: code, Message: message}}), nil
}

// applied builds a Create result carrying artifact metadata (sealed's own
// checksum doubles as ObservedStateDigest: the digest of the observed state
// IS the checksum of the artifact that now exists on disk).
func (c *BackupCapability) applied(op protocol.OperationEnvelope, status protocol.Status, artifactPath string, sealed []byte, included []string) protocol.ResultEnvelope {
	checksum := checksumOf(sealed)

	data, err := json.Marshal(artifactData{
		IncludedCapabilities: included,
		SizeBytes:            int64(len(sealed)),
		Checksum:             checksum,
		ArtifactPath:         artifactPath,
	})
	if err != nil {
		// artifactData is a fixed, always-marshalable shape; this cannot
		// realistically fail, but a nil Data on an applied result would
		// silently drop the one thing this capability exists to report.
		panic(fmt.Sprintf("marshaling backup artifact data: %v", err))
	}

	result := c.buildResult(op, status, nil)
	result.ObservedStateDigest = checksum
	result.Data = data

	return result
}

func (c *BackupCapability) buildResult(op protocol.OperationEnvelope, status protocol.Status, errs []protocol.ResultError) protocol.ResultEnvelope {
	if errs == nil {
		errs = []protocol.ResultError{}
	}

	return protocol.ResultEnvelope{
		ProtocolVersion:      op.ProtocolVersion,
		Capability:           op.Capability,
		ResourceID:           op.ResourceID,
		IdempotencyKey:       op.IdempotencyKey,
		CorrelationID:        op.CorrelationID,
		Status:               status,
		ObservedStateVersion: op.DesiredStateVersion,
		ObservedStateDigest:  zeroDigest,
		GenerationID:         "none",
		Errors:               errs,
		CompletedAt:          time.Now().UTC(),
	}
}
