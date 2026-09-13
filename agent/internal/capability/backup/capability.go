// Package backup implements the backup.encrypted-artifacts.v1 capability: a
// real, encrypted, whole-node snapshot of whichever other capabilities'
// state roots are actually present on this node (see Config.StateRoots),
// written to a fixed, owned artifact directory (Config.ArtifactsRoot).
// Provider-admin-only, node-scoped, local-disk-only for this v1 pass (see
// the Backups design decision, vault): there is no S3-compatible or other
// remote storage backend yet, and no per-account/tenant scoping, since a
// real artifact today commingles every account hosted on the node.
//
// Create and delete do the real archive/encrypt/write and removal work,
// mirroring internal/capability/identity's own "no update/suspend/unsuspend"
// precedent: a backup is either created outright or removed outright, never
// mutated in between (Laravel's own BackupPolicy has no update/suspend/
// unsuspend ability either). Observe is real too, but deliberately not
// drift-detection the way bind9/mail's own observe implementations use it:
// it reads an existing artifact's own sealed bytes back and reports them,
// base64-encoded, on ResultEnvelope.Data, so a provider admin can decrypt
// and download a backup they already made (see PreparesBackupDownload.php
// on the Laravel side, which holds the one encryption key needed to make
// sense of them).
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
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"sort"
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

// observeData is the shape reported on a successful observe's own
// ResultEnvelope.Data, matching PreparesBackupDownload.php's own expected
// key exactly: the artifact's still-sealed (AES-256-GCM encrypted) bytes,
// base64-encoded to survive JSON. Never the plaintext archive and never the
// encryption key itself (Laravel already holds that, and decrypts there):
// this capability only ever reads the same sealed bytes a Delete operation
// would remove, never Decrypt()s them itself.
type observeData struct {
	ArtifactBase64 string `json:"artifact_base64"`
}

// Apply implements protocol.Capability.
func (c *BackupCapability) Apply(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	ctx, cancel := context.WithDeadline(ctx, op.Deadline)
	defer cancel()

	// Observe and Restore are both exempted from the idempotency-receipt
	// cache: Observe must always re-read the artifact's current bytes
	// fresh, never serve a stale cached reply from an earlier download-
	// preparation attempt (the mail capability's own identical exemption),
	// and a cached Restore reply would be actively harmful -- it must
	// always genuinely re-run the real restore, never silently report
	// "already applied" for what is a rare, deliberate, one-shot recovery
	// action.
	if op.Operation != protocol.OperationObserve && op.Operation != protocol.OperationRestore {
		if prior, ok := c.receipts.Lookup(op.IdempotencyKey); ok {
			if prior.Status == protocol.StatusApplied || prior.Status == protocol.StatusAlreadyApplied {
				prior.Status = protocol.StatusAlreadyApplied
			}

			return prior, nil
		}
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
	case protocol.OperationObserve:
		result, err = c.applyObserve(op)
	case protocol.OperationRestore:
		result, err = c.applyRestore(ctx, op)
	default:
		result, err = c.rejected(op, "unsupported_operation",
			fmt.Sprintf("operation %q is not supported; backup.encrypted-artifacts.v1 only implements create, delete, observe, and restore", op.Operation), "")
	}

	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if op.Operation != protocol.OperationObserve && op.Operation != protocol.OperationRestore {
		c.receipts.Record(op.IdempotencyKey, result)
	}

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
		replayIncluded := mergeIncludedCapabilities(included, discoverPresentDumpSockets(c.cfg.DatabaseDumpSockets))

		return c.applied(op, protocol.StatusAlreadyApplied, artifactPath, sealed, replayIncluded), nil
	}

	if ctx.Err() != nil {
		return protocol.ResultEnvelope{}, ctx.Err()
	}

	dumps, dumpedCapabilities, err := dumpDatabases(ctx, c.cfg.DatabaseDumpSockets)
	if err != nil {
		return c.failed(op, "database_dump_failed", err.Error())
	}

	plaintext, err := archiveStateRoots(c.cfg.StateRoots, included, dumps, dumpedCapabilities)
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

	return c.applied(op, protocol.StatusApplied, artifactPath, sealed, mergeIncludedCapabilities(included, dumpedCapabilities)), nil
}

// mergeIncludedCapabilities combines StateRoots-derived and dump-derived
// capability names into one sorted list for artifactData.IncludedCapabilities.
// The two sources are always disjoint by construction (database.control-plane.v1/
// database.tenant.v1 are never StateRoots entries), so this is a plain
// concatenation, not a set union.
func mergeIncludedCapabilities(stateRootCapabilities, dumpCapabilities []string) []string {
	merged := make([]string, 0, len(stateRootCapabilities)+len(dumpCapabilities))
	merged = append(merged, stateRootCapabilities...)
	merged = append(merged, dumpCapabilities...)
	sort.Strings(merged)

	return merged
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

// applyObserve reads the sealed (still AES-256-GCM encrypted) bytes of the
// artifact at payload.ArtifactPath and reports them, base64-encoded, on a
// successful result's own Data field. This capability never decrypts them
// itself: Laravel already holds the plaintext encryption key (see
// Backup::encryption_key's own "control plane holding it is the correct,
// safer home" design), so decryption happens there, in
// PreparesBackupDownload.php, once this result is reported back. Deliberately
// never dispatched by a real desired-state reconciliation the way bind9/
// mail's own observe implementations are: this is a one-shot, user-triggered
// "prepare a download" request, not drift detection, so a StatusDegraded
// outcome (meaning "live state doesn't match the last known-good
// generation") has no meaning here -- success is always StatusApplied.
func (c *BackupCapability) applyObserve(op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParseObservePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(op, verr)
	}

	cleaned := filepath.Clean(*payload.ArtifactPath)
	if !isWithinRoot(cleaned, c.cfg.ArtifactsRoot) {
		return c.rejected(op, "artifact_path_outside_root",
			fmt.Sprintf("artifact_path %q is not within the owned artifacts root %q", *payload.ArtifactPath, c.cfg.ArtifactsRoot), "artifact_path")
	}

	sealed, err := os.ReadFile(cleaned)
	if errors.Is(err, os.ErrNotExist) {
		return c.rejected(op, "artifact_not_found", fmt.Sprintf("no artifact exists at %q", *payload.ArtifactPath), "artifact_path")
	} else if err != nil {
		return c.failed(op, "artifact_read_failed", err.Error())
	}

	data, err := json.Marshal(observeData{ArtifactBase64: base64.StdEncoding.EncodeToString(sealed)})
	if err != nil {
		// observeData is a fixed, always-marshalable shape; this cannot
		// realistically fail, but silently dropping the one thing this
		// operation exists to report would be worse than a loud panic.
		panic(fmt.Sprintf("marshaling backup observe data: %v", err))
	}

	result := c.buildResult(op, protocol.StatusApplied, nil)
	result.Data = data

	return result, nil
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
