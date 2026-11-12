// Package sshaccess implements node.ssh-access.v1: opening and closing the node's ssh port (22)
// from the panel. The firewall baseline always opens it, as recovery access. Closing adds a
// separate nftables table, lesta_ssh, whose chain runs before the baseline and drops new
// connections to the port; opening deletes that table. Sessions already open survive a close, so
// whoever closes the port keeps the shell they are in. The agent reaches the panel by polling it,
// so the panel can always open the port again, however it was closed.
//
// The closed state is kept in a file that Restore loads when the applier starts, so a reboot does
// not open the port again. It runs in the maintenance applier, as root.
package sshaccess

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"strings"
	"time"

	"github.com/mikho/LESta/agent/internal/atomicfile"
	"github.com/mikho/LESta/agent/internal/idempotency"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// Config is the capability's configuration.
type Config struct {
	// NftBinary is the nft used; empty means "nft" (tests use a stand-in).
	NftBinary string
	// ClosedPath is the nft script kept while the port is closed. It is absent while the port is open.
	ClosedPath string
	// Port is the ssh port.
	Port int
}

type payload struct {
	Open *bool `json:"open"`
}

type result struct {
	Open bool `json:"open"`
}

// SSHAccessCapability applies node.ssh-access.v1 operations.
type SSHAccessCapability struct {
	cfg      Config
	receipts *idempotency.Store
}

// New returns the capability.
func New(cfg Config) *SSHAccessCapability {
	if cfg.NftBinary == "" {
		cfg.NftBinary = "nft"
	}

	return &SSHAccessCapability{cfg: cfg, receipts: idempotency.New()}
}

// closedScript is the nft script that closes the port: the table is declared, deleted and declared
// again in one transaction, so it works whether or not the table exists.
func (c Config) closedScript() string {
	return fmt.Sprintf(`add table inet lesta_ssh
delete table inet lesta_ssh
table inet lesta_ssh {
    chain input {
        type filter hook input priority -10; policy accept;
        ct state new tcp dport %d drop
    }
}
`, c.Port)
}

const openScript = "add table inet lesta_ssh\ndelete table inet lesta_ssh\n"

// Restore closes the port again if it was closed, which loading the table after a reboot needs.
// A node whose port was never closed has no file and nothing happens.
func Restore(ctx context.Context, cfg Config) error {
	c := New(cfg)

	if _, err := os.Stat(c.cfg.ClosedPath); err != nil {
		if os.IsNotExist(err) {
			return nil
		}

		return err
	}

	return c.nft(ctx, c.cfg.closedScript())
}

// Apply runs one update operation: {"open": true|false}.
func (c *SSHAccessCapability) Apply(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
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

func (c *SSHAccessCapability) apply(ctx context.Context, op protocol.OperationEnvelope) protocol.ResultEnvelope {
	if op.Operation != protocol.OperationUpdate {
		return c.envelope(op, protocol.StatusRejected, nil, "unsupported_operation", fmt.Sprintf("operation %q is not supported; only update", op.Operation), "")
	}

	var p payload

	dec := json.NewDecoder(bytes.NewReader(op.Payload))
	dec.DisallowUnknownFields()

	if err := dec.Decode(&p); err != nil || p.Open == nil {
		message := "open must be true or false"
		if err != nil {
			message = err.Error()
		}

		return c.envelope(op, protocol.StatusRejected, nil, "invalid_payload", message, "open")
	}

	runCtx, cancel := context.WithTimeout(ctx, 30*time.Second)
	defer cancel()

	if *p.Open {
		if err := c.nft(runCtx, openScript); err != nil {
			return c.envelope(op, protocol.StatusFailed, nil, "firewall_failed", err.Error(), "")
		}

		if err := os.Remove(c.cfg.ClosedPath); err != nil && !os.IsNotExist(err) {
			return c.envelope(op, protocol.StatusFailed, nil, "state_not_saved", err.Error(), "")
		}

		return c.envelope(op, protocol.StatusApplied, &result{Open: true}, "", "", "")
	}

	// The file is written first: if loading the table fails, a reboot still closes the port as
	// the panel asked, and the failure is reported so the panel does not show it as closed.
	if err := atomicfile.Write(c.cfg.ClosedPath, []byte(c.cfg.closedScript()), 0o600); err != nil {
		return c.envelope(op, protocol.StatusFailed, nil, "state_not_saved", err.Error(), "")
	}

	if err := c.nft(runCtx, c.cfg.closedScript()); err != nil {
		return c.envelope(op, protocol.StatusFailed, nil, "firewall_failed", err.Error(), "")
	}

	return c.envelope(op, protocol.StatusApplied, &result{Open: false}, "", "", "")
}

// nft loads script with nft -f -.
func (c *SSHAccessCapability) nft(ctx context.Context, script string) error {
	cmd := exec.CommandContext(ctx, c.cfg.NftBinary, "-f", "-") //nolint:gosec // fixed arguments
	cmd.Stdin = strings.NewReader(script)

	if out, err := cmd.CombinedOutput(); err != nil {
		return fmt.Errorf("nft: %w: %s", err, strings.TrimSpace(string(out)))
	}

	return nil
}

func (c *SSHAccessCapability) envelope(op protocol.OperationEnvelope, status protocol.Status, data *result, code, message, field string) protocol.ResultEnvelope {
	errs := []protocol.ResultError{}

	if code != "" {
		var fieldPtr *string
		if field != "" {
			fieldPtr = &field
		}

		errs = append(errs, protocol.ResultError{Code: code, Message: message, Field: fieldPtr})
	}

	observed := "unchanged"
	res := protocol.ResultEnvelope{
		ProtocolVersion:      op.ProtocolVersion,
		Capability:           op.Capability,
		ResourceID:           op.ResourceID,
		IdempotencyKey:       op.IdempotencyKey,
		CorrelationID:        op.CorrelationID,
		Status:               status,
		ObservedStateVersion: op.DesiredStateVersion,
		GenerationID:         "none",
		Errors:               errs,
		CompletedAt:          time.Now().UTC(),
	}

	if data != nil {
		observed = fmt.Sprintf("open=%t", data.Open)

		if encoded, err := json.Marshal(data); err == nil {
			res.Data = encoded
		}
	}

	sum := sha256.Sum256([]byte(observed))
	res.ObservedStateDigest = "sha256:" + hex.EncodeToString(sum[:])

	return res
}
