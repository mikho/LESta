// Package serviceuninstall implements node.service-uninstall.v1: removing one LESta service from
// the node, asked for in the panel. It runs in the "maintenance" applier, the one root instance
// without a file-system sandbox, because removing a service purges packages and deletes its
// configuration. All it can do is run the node's own installer in its single-service remove mode
// for a service on a fixed list; the installer refuses while another service depends on it, and
// moves a stateful service's data aside instead of deleting it.
package serviceuninstall

import (
	"bytes"
	"context"
	"crypto/sha256"
	"encoding/hex"
	"encoding/json"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"syscall"
	"time"

	"github.com/mikho/LESta/agent/internal/idempotency"
	"github.com/mikho/LESta/agent/internal/protocol"
)

// Services are the services the panel may remove: the installer's selectable services.
var Services = []string{"nginx", "apache", "bind9", "mariadb", "cron", "mail", "backups", "php-fpm", "adminer", "webmail"}

// Config is the capability's configuration.
type Config struct {
	// Installer is the orchestrator script (production: /opt/lesta/.install/scripts/install-selected.sh).
	Installer string
	// Shell runs it; empty means /bin/sh.
	Shell string
	// Timeout bounds one removal; zero means 25 minutes.
	Timeout time.Duration
	// TrustInstaller checks the installer before it runs; nil means rootOwned. Tests replace it,
	// since a test's temporary directory is never root-owned all the way up.
	TrustInstaller func(path string) error
}

type payload struct {
	Service         string `json:"service"`
	ConfirmDataLoss bool   `json:"confirm_data_loss"`
}

// installerResult is the part of the installer's final JSON line this capability reads.
type installerResult struct {
	Status  string `json:"status"`
	Changes []struct {
		Action string `json:"action"`
		Path   string `json:"path"`
		Detail string `json:"detail"`
	} `json:"changes"`
	Errors []struct {
		Code    string `json:"code"`
		Message string `json:"message"`
	} `json:"errors"`
}

// ServiceUninstallCapability applies node.service-uninstall.v1 operations.
type ServiceUninstallCapability struct {
	cfg      Config
	receipts *idempotency.Store
}

// New returns the capability.
func New(cfg Config) *ServiceUninstallCapability {
	if cfg.Shell == "" {
		cfg.Shell = "/bin/sh"
	}

	if cfg.Timeout == 0 {
		cfg.Timeout = 25 * time.Minute
	}

	if cfg.TrustInstaller == nil {
		cfg.TrustInstaller = rootOwned
	}

	return &ServiceUninstallCapability{cfg: cfg, receipts: idempotency.New()}
}

// Apply runs one delete operation: {"service": ..., "confirm_data_loss": bool}.
func (c *ServiceUninstallCapability) Apply(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
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

func (c *ServiceUninstallCapability) apply(ctx context.Context, op protocol.OperationEnvelope) protocol.ResultEnvelope {
	if op.Operation != protocol.OperationDelete {
		return c.envelope(op, protocol.StatusRejected, "", []protocol.ResultError{resultError("unsupported_operation", fmt.Sprintf("operation %q is not supported; only delete", op.Operation))}, nil)
	}

	var p payload

	dec := json.NewDecoder(bytes.NewReader(op.Payload))
	dec.DisallowUnknownFields()

	if err := dec.Decode(&p); err != nil {
		return c.envelope(op, protocol.StatusRejected, "", []protocol.ResultError{resultError("invalid_payload", err.Error())}, nil)
	}

	if !allowed(p.Service) {
		return c.envelope(op, protocol.StatusRejected, "", []protocol.ResultError{resultError("service_not_allowed", fmt.Sprintf("%q is not a service the panel may remove", p.Service))}, nil)
	}

	if err := c.cfg.TrustInstaller(c.cfg.Installer); err != nil {
		return c.envelope(op, protocol.StatusFailed, "", []protocol.ResultError{resultError("installer_untrusted", err.Error())}, nil)
	}

	args := []string{c.cfg.Installer, "--apply", "--yes", "--remove", p.Service}
	if p.ConfirmDataLoss {
		args = append(args, "--confirm-data-loss", p.Service)
	}

	runCtx, cancel := context.WithTimeout(ctx, c.cfg.Timeout)
	defer cancel()

	var stdout, stderr bytes.Buffer

	cmd := exec.CommandContext(runCtx, c.cfg.Shell, args...) //nolint:gosec // fixed script, allow-listed service
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr
	runErr := cmd.Run()

	result, parseErr := lastResult(stdout.String())
	if parseErr != nil {
		message := fmt.Sprintf("the installer gave no result (%v): %s", runErr, tail(stderr.String()))

		return c.envelope(op, protocol.StatusFailed, p.Service+":unknown", []protocol.ResultError{resultError("installer_failed", message)}, nil)
	}

	data, _ := json.Marshal(map[string]any{"service": p.Service, "changes": result.Changes})

	if result.Status != "applied" {
		errs := make([]protocol.ResultError, 0, len(result.Errors))
		for _, e := range result.Errors {
			errs = append(errs, resultError(e.Code, e.Message))
		}

		if len(errs) == 0 {
			errs = append(errs, resultError("installer_failed", fmt.Sprintf("the installer reported %q", result.Status)))
		}

		return c.envelope(op, protocol.StatusFailed, p.Service+":"+result.Status, errs, data)
	}

	return c.envelope(op, protocol.StatusApplied, p.Service+":removed", nil, data)
}

// rootOwned refuses to run an installer that anyone but root could have changed: the script and
// every directory above it must be owned by root and not writable by group or others. This instance
// runs it as unsandboxed root.
func rootOwned(path string) error {
	for p := filepath.Clean(path); ; p = filepath.Dir(p) {
		info, err := os.Stat(p)
		if err != nil {
			return fmt.Errorf("checking %s: %w", p, err)
		}

		st, ok := info.Sys().(*syscall.Stat_t)
		if !ok || st.Uid != 0 || info.Mode().Perm()&0o022 != 0 {
			return fmt.Errorf("%s must be owned by root and writable only by root", p)
		}

		if p == filepath.Dir(p) {
			return nil
		}
	}
}

func allowed(service string) bool {
	for _, s := range Services {
		if s == service {
			return true
		}
	}

	return false
}

// lastResult reads the installer's final line, its JSON result.
func lastResult(stdout string) (installerResult, error) {
	lines := strings.Split(strings.TrimSpace(stdout), "\n")

	var r installerResult

	if len(lines) == 0 || json.Unmarshal([]byte(lines[len(lines)-1]), &r) != nil || r.Status == "" {
		return installerResult{}, fmt.Errorf("no JSON result on the last line")
	}

	return r, nil
}

func tail(s string) string {
	s = strings.TrimSpace(s)
	if len(s) > 2000 {
		return s[len(s)-2000:]
	}

	return s
}

func resultError(code, message string) protocol.ResultError {
	return protocol.ResultError{Code: code, Message: message}
}

func (c *ServiceUninstallCapability) envelope(op protocol.OperationEnvelope, status protocol.Status, observed string, errs []protocol.ResultError, data json.RawMessage) protocol.ResultEnvelope {
	if errs == nil {
		errs = []protocol.ResultError{}
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
		Data:                 data,
	}
}
