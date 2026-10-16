// Package servicecontrol implements node.service-control.v1: starting, stopping and restarting
// one of the node's services from the panel. It runs in the root applier and only ever touches a
// unit on its fixed allow list; the daemon's own units, the appliers, the firewall, ssh and the
// control plane's database are never on it, so the panel cannot cut itself off from a node.
package servicecontrol

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os/exec"
	"regexp"
	"strings"
	"time"

	"github.com/mikho/LESta/agent/internal/idempotency"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// Config is the capability's configuration.
type Config struct {
	// SystemctlBinary is the systemctl used; empty means "systemctl" (tests use a stand-in).
	SystemctlBinary string
	// AllowedUnits is the fixed list of units the panel may control.
	AllowedUnits []string
}

// DefaultAllowedUnits are the customer-facing services: the web servers, DNS, every PHP version's
// FastCGI manager, the tenant database, cron and mail.
var DefaultAllowedUnits = []string{
	"nginx", "apache2", "named",
	"php8.1-fpm", "php8.2-fpm", "php8.3-fpm", "php8.4-fpm",
	"mariadb@tenant", "cron", "exim4", "dovecot",
}

var actions = map[string]bool{"start": true, "stop": true, "restart": true}

var unitPattern = regexp.MustCompile(`^[a-z0-9@._-]{1,64}$`)

type payload struct {
	Unit   string `json:"unit"`
	Action string `json:"action"`
}

// result is the data an applied operation reports: the unit and the state it is in afterwards.
type result struct {
	Unit  string `json:"unit"`
	State string `json:"state"`
}

// ServiceControlCapability applies node.service-control.v1 operations.
type ServiceControlCapability struct {
	cfg      Config
	receipts *idempotency.Store
}

// New returns the capability.
func New(cfg Config) *ServiceControlCapability {
	if cfg.SystemctlBinary == "" {
		cfg.SystemctlBinary = "systemctl"
	}

	return &ServiceControlCapability{cfg: cfg, receipts: idempotency.New()}
}

// Apply runs one update operation: {"unit": ..., "action": "start"|"stop"|"restart"}.
func (c *ServiceControlCapability) Apply(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
	if prior, ok := c.receipts.Lookup(op.IdempotencyKey); ok {
		if prior.Status == protocol.StatusApplied {
			prior.Status = protocol.StatusAlreadyApplied
		}

		return prior, nil
	}

	res := c.apply(ctx, op)
	c.receipts.Record(op.IdempotencyKey, res)

	return res, nil
}

func (c *ServiceControlCapability) apply(ctx context.Context, op protocol.OperationEnvelope) protocol.ResultEnvelope {
	if op.Operation != protocol.OperationUpdate {
		return c.envelope(op, protocol.StatusRejected, "", "unsupported_operation", fmt.Sprintf("operation %q is not supported; only update", op.Operation), "")
	}

	var p payload

	dec := json.NewDecoder(bytes.NewReader(op.Payload))
	dec.DisallowUnknownFields()

	if err := dec.Decode(&p); err != nil {
		return c.envelope(op, protocol.StatusRejected, "", "invalid_payload", err.Error(), "")
	}

	if !actions[p.Action] {
		return c.envelope(op, protocol.StatusRejected, "", "invalid_action", "action must be start, stop or restart", "action")
	}

	if !unitPattern.MatchString(p.Unit) || !c.allowed(p.Unit) {
		return c.envelope(op, protocol.StatusRejected, "", "unit_not_allowed", fmt.Sprintf("%q is not a service the panel may control", p.Unit), "unit")
	}

	runCtx, cancel := context.WithTimeout(ctx, 90*time.Second)
	defer cancel()

	out, err := exec.CommandContext(runCtx, c.cfg.SystemctlBinary, p.Action, "--", p.Unit).CombinedOutput() //nolint:gosec // allow-listed unit, fixed actions
	state := c.state(ctx, p.Unit)

	if err != nil {
		return c.envelopeWithData(op, protocol.StatusFailed, p.Unit, state, "systemctl_failed",
			fmt.Sprintf("systemctl %s %s: %v: %s", p.Action, p.Unit, err, strings.TrimSpace(string(out))))
	}

	return c.envelopeWithData(op, protocol.StatusApplied, p.Unit, state, "", "")
}

func (c *ServiceControlCapability) allowed(unit string) bool {
	for _, u := range c.cfg.AllowedUnits {
		if u == unit {
			return true
		}
	}

	return false
}

// state is the unit's active state after the action, "unknown" if it cannot be read.
func (c *ServiceControlCapability) state(ctx context.Context, unit string) string {
	out, err := exec.CommandContext(ctx, c.cfg.SystemctlBinary, "show", "--property=ActiveState", "--value", "--", unit).Output() //nolint:gosec // allow-listed unit
	if err != nil {
		return "unknown"
	}

	return strings.TrimSpace(string(out))
}

func (c *ServiceControlCapability) envelopeWithData(op protocol.OperationEnvelope, status protocol.Status, unit, state, code, message string) protocol.ResultEnvelope {
	res := c.envelope(op, status, unit+":"+state, code, message, "")

	if data, err := json.Marshal(result{Unit: unit, State: state}); err == nil {
		res.Data = data
	}

	return res
}

func (c *ServiceControlCapability) envelope(op protocol.OperationEnvelope, status protocol.Status, observed, code, message, field string) protocol.ResultEnvelope {
	errs := []protocol.ResultError{}

	if code != "" {
		var fieldPtr *string
		if field != "" {
			fieldPtr = &field
		}

		errs = append(errs, protocol.ResultError{Code: code, Message: message, Field: fieldPtr})
	}

	sum := sha256.Sum256([]byte(observed))

	return protocol.ResultEnvelope{
		ProtocolVersion:      op.ProtocolVersion,
		Capability:           op.Capability,
		ResourceID:           op.ResourceID,
		IdempotencyKey:       op.IdempotencyKey,
		CorrelationID:        op.CorrelationID,
		Status:               status,
		ObservedStateVersion: op.DesiredStateVersion,
		ObservedStateDigest:  "sha256:" + hex.EncodeToString(sum[:]),
		GenerationID:         "none",
		Errors:               errs,
		CompletedAt:          time.Now().UTC(),
	}
}
