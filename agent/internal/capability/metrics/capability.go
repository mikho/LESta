// Package metrics implements the metrics.usage.v1 capability: real,
// bounded, incremental usage collection for the statistics vertical (see
// .install/services/statistics/README.md's own constraints -- read tenant-
// database state only through the dedicated, least-privilege stats_user/
// stats_password from Phase 27, never touch the control-plane schema or
// /var/log/auth.log).
//
// This capability's own shape is deliberately different from every other
// capability in this module: it never provisions anything (no create/
// update/suspend/unsuspend/delete), it only ever observes. A single
// observe operation's payload is a manifest of every mail account/tenant
// database/web-facing vhost to measure on this node, assembled by Laravel
// from its own database -- this capability has no independent way to
// discover which accounts exist here (unlike mail/bind9's own self-
// discovering StateRoot enumeration, since usage measurement reads
// OTHER capabilities' own already-rendered state, e.g. Dovecot's real
// maildir layout and nginx/apache's own per-vhost access logs, rather than
// maintaining any state of its own to enumerate). The measured values are
// reported back per resource_uuid via ResultEnvelope's own Data field,
// exactly like backup.encrypted-artifacts.v1's own artifact metadata; a
// Laravel-side completion hook attributes each number back to its owning
// account, since this capability has no concept of "account" at all, only
// bare resource identifiers.
//
// A partial failure (one resource's own measurement fails -- a mailbox
// directory unreadable, a stats credential rejected, a malformed log) never
// fails the whole operation: every other resource's own real measurement
// still gets collected and reported, and the overall result is
// StatusDegraded, not StatusFailed, with the specific failures listed in
// Errors. Bounded, incremental collection (per the design constraint above)
// means one missed resource on one cycle is recoverable at the next cycle,
// never worth discarding an otherwise-successful collection over.
package metrics

import (
	"context"
	"encoding/json"
	"errors"
	"fmt"
	"time"

	"github.com/mikho/LESta/agent/internal/idempotency"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// zeroDigest is the fixed observed_state_digest this capability always
// reports: it has no rendered config tree or generation history of its own
// to fingerprint (see this package's own doc comment on why), mirroring
// internal/capability/identity's own use of the same fixed placeholder for
// the same underlying reason.
const zeroDigest = "sha256:0000000000000000000000000000000000000000000000000000000000000000"

// MetricsCapability implements protocol.Capability for metrics.usage.v1.
type MetricsCapability struct {
	cfg      Config
	receipts *idempotency.Store
}

// New returns a MetricsCapability using cfg.
func New(cfg Config) *MetricsCapability {
	return &MetricsCapability{
		cfg:      cfg,
		receipts: idempotency.New(),
	}
}

type mailAccountUsage struct {
	ResourceUUID string `json:"resource_uuid"`
	DiskBytes    int64  `json:"disk_bytes"`
}

type tenantDatabaseUsage struct {
	ResourceUUID string `json:"resource_uuid"`
	DiskBytes    int64  `json:"disk_bytes"`
}

type webResourceUsage struct {
	ResourceUUID string `json:"resource_uuid"`
	RequestCount int64  `json:"request_count"`
	BytesSent    int64  `json:"bytes_sent"`
}

type resultData struct {
	MailAccounts    []mailAccountUsage    `json:"mail_accounts"`
	TenantDatabases []tenantDatabaseUsage `json:"tenant_databases"`
	WebResources    []webResourceUsage    `json:"web_resources"`
}

// Apply implements protocol.Capability.
func (c *MetricsCapability) Apply(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	ctx, cancel := context.WithDeadline(ctx, op.Deadline)
	defer cancel()

	if prior, ok := c.receipts.Lookup(op.IdempotencyKey); ok {
		return prior, nil
	}

	if op.Operation != protocol.OperationObserve {
		result := c.rejected(op, "unsupported_operation",
			fmt.Sprintf("operation %q is not supported; metrics.usage.v1 only implements observe", op.Operation), "")
		c.receipts.Record(op.IdempotencyKey, result)

		return result, nil
	}

	payload, verr := ParsePayload(op.Payload)
	if verr != nil {
		result, err := c.rejectedFromValidationError(op, verr)
		if err != nil {
			return protocol.ResultEnvelope{}, err
		}

		c.receipts.Record(op.IdempotencyKey, result)

		return result, nil
	}

	var data resultData

	var errs []protocol.ResultError

	for _, m := range payload.MailAccounts {
		diskBytes, err := mailboxDiskBytes(c.cfg.VmailRoot, m.Domain, m.LocalPart)
		if err != nil {
			errs = append(errs, protocol.ResultError{
				Code:    "mailbox_measurement_failed",
				Message: fmt.Sprintf("%s: %v", m.ResourceUUID, err),
			})

			continue
		}

		data.MailAccounts = append(data.MailAccounts, mailAccountUsage{ResourceUUID: m.ResourceUUID, DiskBytes: diskBytes})
	}

	for _, d := range payload.TenantDatabases {
		diskBytes, err := databaseDiskBytes(ctx, c.cfg, d)
		if err != nil {
			errs = append(errs, protocol.ResultError{
				Code:    "database_measurement_failed",
				Message: fmt.Sprintf("%s: %v", d.ResourceUUID, err),
			})

			continue
		}

		data.TenantDatabases = append(data.TenantDatabases, tenantDatabaseUsage{ResourceUUID: d.ResourceUUID, DiskBytes: diskBytes})
	}

	for _, w := range payload.WebResources {
		usage, err := collectWebUsage(c.cfg, w)
		if err != nil {
			errs = append(errs, protocol.ResultError{
				Code:    "web_measurement_failed",
				Message: fmt.Sprintf("%s: %v", w.ResourceUUID, err),
			})

			continue
		}

		data.WebResources = append(data.WebResources, webResourceUsage{ResourceUUID: w.ResourceUUID, RequestCount: usage.RequestCount, BytesSent: usage.BytesSent})
	}

	status := protocol.StatusApplied
	if len(errs) > 0 {
		status = protocol.StatusDegraded
	}

	rawData, err := json.Marshal(data)
	if err != nil {
		return protocol.ResultEnvelope{}, fmt.Errorf("marshaling metrics usage data: %w", err)
	}

	if errs == nil {
		errs = []protocol.ResultError{}
	}

	result := protocol.ResultEnvelope{
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
		Data:                 rawData,
	}

	c.receipts.Record(op.IdempotencyKey, result)

	return result, nil
}

func (c *MetricsCapability) rejectedFromValidationError(op protocol.OperationEnvelope, err error) (protocol.ResultEnvelope, error) {
	var ve *ValidationError
	if errors.As(err, &ve) {
		return c.rejected(op, ve.Code, ve.Message, ve.Field), nil
	}

	return protocol.ResultEnvelope{}, err
}

func (c *MetricsCapability) rejected(op protocol.OperationEnvelope, code, message, field string) protocol.ResultEnvelope {
	var fieldPtr *string
	if field != "" {
		fieldPtr = &field
	}

	return protocol.ResultEnvelope{
		ProtocolVersion:      op.ProtocolVersion,
		Capability:           op.Capability,
		ResourceID:           op.ResourceID,
		IdempotencyKey:       op.IdempotencyKey,
		CorrelationID:        op.CorrelationID,
		Status:               protocol.StatusRejected,
		ObservedStateVersion: 0,
		ObservedStateDigest:  zeroDigest,
		GenerationID:         "none",
		Errors:               []protocol.ResultError{{Code: code, Message: message, Field: fieldPtr}},
		CompletedAt:          time.Now().UTC(),
	}
}
