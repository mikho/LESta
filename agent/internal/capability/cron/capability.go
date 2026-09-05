// Package cron implements the scheduler.account-cron.v1 capability: real
// account-scoped cron jobs, provisioned as crontab fragments under
// /etc/cron.d. Like internal/capability/acme, this capability never renders
// a template through an external validator, never invokes a reload binary,
// and never runs a health-check poll, and so has no rollback/recovery path
// either: a crontab fragment write either succeeds outright (StatusApplied)
// or fails outright (a plain Go error, the "no verdict reached" bucket),
// never something with an intermediate staged-but-not-activated state to
// unwind. Linux's own cron daemon polls /etc/cron.d for changes on its own
// schedule, so, exactly like acme's own files, there is no reload signal to
// send. Unlike acme's arbitrary blobs, though, a crontab fragment genuinely
// is a file a daemon watches, so this capability borrows nginx's/bind9's own
// atomic write-then-rename discipline for it (see activate.go).
//
// The key security design: a tenant's raw command text is NEVER embedded
// into the crontab fragment file's own line. That line only ever invokes a
// fixed wrapper, "<AgentBinaryPath> cron-run <resource_id>", where
// resource_id is the server-generated UUID carried on every
// OperationEnvelope, never tenant input. The wrapper (runner.go's RunJob,
// wired into a new CLI mode in cmd/lesta-agent/main.go) reads the real
// command from a separate JSON sidecar file at execution time. This
// architecturally eliminates crontab-line injection via the command field,
// rather than relying on text-escaping as the primary safeguard. The five
// schedule fields (minute/hour/day_of_month/month/day_of_week) ARE embedded
// directly in the crontab line, so those alone get strict, numerically
// range-checked validation (see payload.go's own validateCronField) -- they
// are the only tenant-influenced content that ever reaches the crontab
// file's own syntax directly.
package cron

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"path/filepath"
	"strconv"
	"time"

	"github.com/mikho/LESta/agent/internal/generation"
	"github.com/mikho/LESta/agent/internal/idempotency"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// CronCapability implements protocol.Capability for scheduler.account-cron.v1.
type CronCapability struct {
	cfg      Config
	store    *generation.Store
	receipts *idempotency.Store
}

// New returns a CronCapability rooted at cfg. Generation history (a
// monotonic per-resource-id operation log used only for idempotency
// bookkeeping and observe's own drift detection -- never a rollback target;
// see this package's own doc comment) nests under cfg.StateRoot/jobs,
// mirroring acme's/nginx's/bind9's own StateRoot/<noun> convention. This is
// namespaced away from the JSON sidecar files (cfg.StateRoot/jobs/sidecar/)
// so neither writer's own file layout can collide with the other's.
func New(cfg Config) *CronCapability {
	return &CronCapability{
		cfg:      cfg,
		store:    generation.New(filepath.Join(cfg.StateRoot, "jobs")),
		receipts: idempotency.New(),
	}
}

// Apply implements protocol.Capability. There is no rollback/recovery path
// here, for the same reason internal/capability/acme's own package doc
// comment gives for having none: a file write either succeeds outright or
// fails outright, never leaving a half-applied intermediate state a restore
// step would need to unwind.
func (c *CronCapability) Apply(_ context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	// observe never applies anything, so it is never deduplicated: there is
	// no side effect a duplicate observe could redundantly redo.
	if op.Operation != protocol.OperationObserve {
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
	case protocol.OperationCreate, protocol.OperationUpdate, protocol.OperationSuspend, protocol.OperationUnsuspend:
		result, err = c.applyWrite(op)
	case protocol.OperationDelete:
		result, err = c.applyDelete(op)
	case protocol.OperationObserve:
		result, err = c.observe(op)
	default:
		result, err = c.rejected(op, "unsupported_operation", fmt.Sprintf("operation %q is not supported", op.Operation), "")
	}

	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if op.Operation != protocol.OperationObserve {
		c.receipts.Record(op.IdempotencyKey, result)
	}

	return result, nil
}

// applyWrite handles create/update/suspend/unsuspend: all four are simply
// "render the fragment (and sidecar) for the payload's own current state and
// write them", since CronJob::toProvisioningPayload() always carries the
// full desired state (including suspended) regardless of which verb
// triggered it, mirroring bind9's own zone-rewrite-on-every-verb shape more
// than mariadb's own per-verb-DDL shape.
func (c *CronCapability) applyWrite(op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParsePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(op, verr)
	}

	fragment := renderFragment(c.cfg, payload, op.ResourceID)
	if err := writeFileAtomic(c.fragmentPath(op.ResourceID), fragment, 0o644); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	// The account's own owned root must exist (root:<run_as> mode 2750,
	// setgid) before writeFileAtomic's own MkdirAll creates the ordinary
	// 0755 jobs/sidecar subdirectory nested inside it: the outer directory
	// is what actually restricts traversal to this one account's own
	// dedicated Linux user, so it must be established with the right
	// ownership first, not left to MkdirAll's own default mode.
	if err := ensureAccountDir(c.cfg.StateRoot, payload.RunAs); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	sidecar, err := json.Marshal(sidecarContent{Command: payload.Command})
	if err != nil {
		return protocol.ResultEnvelope{}, fmt.Errorf("encoding sidecar for %s: %w", op.ResourceID, err)
	}

	if err := writeFileAtomic(c.sidecarPath(payload.RunAs, op.ResourceID), sidecar, 0o640); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	return c.recordGenerationAndBuildResult(op, payload, false)
}

// applyDelete removes both the crontab fragment and its JSON sidecar,
// tolerating os.IsNotExist on either so a retried delete (or a delete for a
// resource whose create never actually landed) stays idempotent.
func (c *CronCapability) applyDelete(op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	payload, verr := ParsePayload(op.Payload)
	if verr != nil {
		return c.rejectedFromValidationError(op, verr)
	}

	if err := os.Remove(c.fragmentPath(op.ResourceID)); err != nil && !os.IsNotExist(err) {
		return protocol.ResultEnvelope{}, fmt.Errorf("removing crontab fragment for %s: %w", op.ResourceID, err)
	}

	if err := os.Remove(c.sidecarPath(payload.RunAs, op.ResourceID)); err != nil && !os.IsNotExist(err) {
		return protocol.ResultEnvelope{}, fmt.Errorf("removing sidecar for %s: %w", op.ResourceID, err)
	}

	return c.recordGenerationAndBuildResult(op, payload, true)
}

// observe is read-only: it recomputes the whole-FragmentDir digest and
// compares it to the recorded generation's manifest. It never writes or
// removes anything.
func (c *CronCapability) observe(op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	n, ok, err := c.store.CurrentGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}
	if !ok {
		return c.rejected(op, "unknown_resource", "no generation history exists for this resource on this node", "")
	}

	manifest, err := c.store.ReadManifest(op.ResourceID, n)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	liveDigest, err := computeDigest(c.cfg.FragmentDir)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	if liveDigest == manifest.Digest {
		return c.buildResult(op, protocol.StatusApplied, manifest.DesiredStateVersion, strconv.Itoa(n), nil)
	}

	return c.buildResult(op, protocol.StatusDegraded, manifest.DesiredStateVersion, strconv.Itoa(n), []protocol.ResultError{
		{Code: "drift_detected", Message: fmt.Sprintf("live digest %s does not match generation %d's recorded digest %s", liveDigest, n, manifest.Digest)},
	})
}

// sidecarContent is the JSON sidecar's own on-disk shape: the real command
// text, and nothing else. The cron-run wrapper (runner.go) is the sidecar's
// only reader.
type sidecarContent struct {
	Command string `json:"command"`
}

// renderFragment builds a crontab fragment's exact file content for
// payload/resourceID. A suspended job renders as a comment-only placeholder
// (mirroring nginx's/bind9's own "suspended renders differently" pattern):
// cron never runs a commented-out line, and the fragment stays present (not
// deleted), so re-activating a suspension is a plain re-render, not a
// recreate. An active job's line follows /etc/cron.d's own format, which --
// unlike a personal crontab -- requires a user-column between the schedule
// and the command.
//
// The wrapper invocation itself carries payload.RunAs a second time, as its
// own trailing CLI argument ("cron-run <resource_id> <run_as>"), not just in
// the line's own user-column: cron switches the *process's* identity to the
// user-column value before ever exec'ing this line, but RunJob (the process
// that identity switch lands in) still needs to know which account's own
// StateRoot/accounts/<run_as> directory to read its sidecar from, and it has
// no other way to learn that -- it cannot list StateRoot/accounts itself to
// find it (that would require read access to every other account's own
// directory too, defeating the very isolation this phase exists to add), so
// the crontab line must simply say it twice.
func renderFragment(cfg Config, payload Payload, resourceID string) []byte {
	if payload.Suspended {
		return []byte(fmt.Sprintf("# lesta-cron: job %s suspended\n", resourceID))
	}

	return []byte(fmt.Sprintf("%s %s %s %s %s %s %s cron-run %s %s\n",
		payload.Minute, payload.Hour, payload.DayOfMonth, payload.Month, payload.DayOfWeek,
		payload.RunAs, cfg.AgentBinaryPath, resourceID, payload.RunAs))
}

func (c *CronCapability) fragmentPath(resourceID string) string {
	return filepath.Join(c.cfg.FragmentDir, "lesta-"+resourceID)
}

// sidecarPath returns this resource's own JSON sidecar path, keyed by
// runAs: StateRoot/accounts/<run_as>/jobs/sidecar/<resource_id>.json. Per-
// account, not a single shared StateRoot/jobs/sidecar/ directory, so the
// account's own dedicated Linux user (as a member of its own same-named
// primary group; see internal/capability/identity's own createSystemUser
// doc comment) can read its own sidecar via a directory that is never
// group-readable by the broader shared `lesta` group -- ensureAccountDir
// creates/chowns/chmods StateRoot/accounts/<run_as> itself, root:<run_as>
// mode 2750, the first time any resource is written under it.
func (c *CronCapability) sidecarPath(runAs, resourceID string) string {
	return filepath.Join(c.accountDir(runAs), "jobs", "sidecar", resourceID+".json")
}

// accountDir returns StateRoot/accounts/<run_as>, this account's own owned
// root under this capability's shared StateRoot.
func (c *CronCapability) accountDir(runAs string) string {
	return filepath.Join(c.cfg.StateRoot, "accounts", runAs)
}

// recordGenerationAndBuildResult persists payload as this resource's next
// generation's metadata (the full payload, including Command: this is
// node-local bookkeeping under StateRoot, never the crontab fragment itself,
// so storing the real command here is no different from the sidecar already
// doing so) and builds the resulting ResultEnvelope.
func (c *CronCapability) recordGenerationAndBuildResult(op protocol.OperationEnvelope, payload Payload, deleted bool) (protocol.ResultEnvelope, error) {
	n, err := c.store.NextGeneration(op.ResourceID)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	digest, err := computeDigest(c.cfg.FragmentDir)
	if err != nil {
		return protocol.ResultEnvelope{}, err
	}

	metaBytes, err := json.Marshal(payload)
	if err != nil {
		return protocol.ResultEnvelope{}, fmt.Errorf("encoding generation metadata: %w", err)
	}

	if err := c.store.Activate(op.ResourceID, n, metaBytes, deleted, digest, op.DesiredStateVersion); err != nil {
		return protocol.ResultEnvelope{}, err
	}

	return c.buildResult(op, protocol.StatusApplied, op.DesiredStateVersion, strconv.Itoa(n), nil)
}

func (c *CronCapability) rejectedFromValidationError(op protocol.OperationEnvelope, err error) (protocol.ResultEnvelope, error) {
	var ve *ValidationError
	if errors.As(err, &ve) {
		return c.rejected(op, ve.Code, ve.Message, ve.Field)
	}

	return protocol.ResultEnvelope{}, err
}

func (c *CronCapability) rejected(op protocol.OperationEnvelope, code, message, field string) (protocol.ResultEnvelope, error) {
	var fieldPtr *string
	if field != "" {
		fieldPtr = &field
	}

	return c.buildResult(op, protocol.StatusRejected, c.currentObservedVersionOrZero(op.ResourceID), c.currentGenerationIDOrNone(op.ResourceID),
		[]protocol.ResultError{{Code: code, Message: message, Field: fieldPtr}})
}

// buildResult always recomputes the current whole-FragmentDir digest fresh:
// even a rejected result must carry a schema-valid observed_state_digest,
// and the current live state is always well-defined regardless of this
// operation's outcome.
func (c *CronCapability) buildResult(op protocol.OperationEnvelope, status protocol.Status, observedVersion int, generationID string, errs []protocol.ResultError) (protocol.ResultEnvelope, error) {
	digest, err := computeDigest(c.cfg.FragmentDir)
	if err != nil {
		return protocol.ResultEnvelope{}, fmt.Errorf("computing observed digest: %w", err)
	}

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
		ObservedStateVersion: observedVersion,
		ObservedStateDigest:  digest,
		GenerationID:         generationID,
		Errors:               errs,
		CompletedAt:          time.Now().UTC(),
	}, nil
}

// currentGenerationIDOrNone reports resourceID's current generation as a
// string, or "none" if this node has no generation history for it at all.
func (c *CronCapability) currentGenerationIDOrNone(resourceID string) string {
	n, ok, err := c.store.CurrentGeneration(resourceID)
	if err != nil || !ok {
		return "none"
	}

	return strconv.Itoa(n)
}

func (c *CronCapability) currentObservedVersionOrZero(resourceID string) int {
	n, ok, err := c.store.CurrentGeneration(resourceID)
	if err != nil || !ok {
		return 0
	}

	manifest, err := c.store.ReadManifest(resourceID, n)
	if err != nil {
		return 0
	}

	return manifest.DesiredStateVersion
}
