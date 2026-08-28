// Command lesta-agent is a one-shot stdin/stdout envelope pipe, not a daemon:
// it reads exactly one OperationEnvelope from stdin, applies it, and writes
// exactly one ResultEnvelope to stdout, then exits. This matches the phase's
// explicit scope: no network transport (HTTP/gRPC server, mTLS enrollment)
// exists yet between Laravel and a running agent; that is out of scope for
// this phase.
//
// Two capabilities are wired up: web.nginx.v1 and dns.bind9.v1.
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"os"

	"github.com/mikho/LESta/agent/internal/capability/bind9"
	"github.com/mikho/LESta/agent/internal/capability/nginx"
	"github.com/mikho/LESta/agent/internal/protocol"
)

const (
	webNginxCapability = "web.nginx.v1"
	dnsBind9Capability = "dns.bind9.v1"
)

func main() {
	if err := run(os.Stdin, os.Stdout); err != nil {
		fmt.Fprintln(os.Stderr, "lesta-agent:", err)
		os.Exit(1)
	}
}

func run(stdin *os.File, stdout *os.File) error {
	var op protocol.OperationEnvelope

	dec := json.NewDecoder(stdin)
	dec.DisallowUnknownFields()

	if err := dec.Decode(&op); err != nil {
		return fmt.Errorf("decoding operation envelope from stdin: %w", err)
	}

	var capability protocol.Capability

	switch op.Capability {
	case webNginxCapability:
		capability = nginx.New(nginxProductionConfig())
	case dnsBind9Capability:
		capability = bind9.New(bind9ProductionConfig())
	default:
		return fmt.Errorf("unsupported capability %q; this build only implements %q and %q", op.Capability, webNginxCapability, dnsBind9Capability)
	}

	result, err := capability.Apply(context.Background(), op)
	if err != nil {
		return fmt.Errorf("applying operation: %w", err)
	}

	enc := json.NewEncoder(stdout)
	enc.SetIndent("", "  ")

	if err := enc.Encode(result); err != nil {
		return fmt.Errorf("encoding result envelope to stdout: %w", err)
	}

	return nil
}

// nginxProductionConfig points at the real, fixed host paths this phase's own
// ADR reconciliation settled on. Creating these paths (and nginx.conf's own
// `include /etc/nginx/lesta.d/*.conf;` line) is a bootstrap-installer
// precondition this phase's code requires, not something it creates itself.
//
// This is deliberately not configurable via environment variables or flags:
// NginxBinary is passed straight into an exec.Command call (see
// internal/capability/nginx/validate.go and reload.go), so making it
// externally overridable would let anything able to set this process's
// environment redirect a privileged exec to an arbitrary executable. The
// bootstrap installer's own self-test (.install/services/nginx/install.sh's
// bootstrap_node_health phase) proves this exact, unmodified binary works by
// running it against the real production paths after install_nginx has
// created them, using a disposable resource it creates and then deletes, not
// by parameterizing the binary itself.
func nginxProductionConfig() nginx.Config {
	return nginx.Config{
		LiveDir:       "/etc/nginx/lesta.d",
		StateRoot:     "/var/lib/lesta/nginx",
		NginxConfPath: "/etc/nginx/nginx.conf",
		NginxBinary:   "nginx",
		Port:          80,
	}
}

// bind9ProductionConfig points at the real, fixed host paths and binaries
// this phase's own ADR reconciliation settled on for dns.bind9.v1, mirroring
// nginxProductionConfig's own non-configurability rationale exactly:
// NamedCheckconfBinary and RndcBinary are passed straight into exec.Command
// calls (see internal/capability/bind9/validate.go and reload.go), so making
// them externally overridable would let anything able to set this process's
// environment redirect a privileged exec to an arbitrary executable.
//
// Nameservers is a real, production-meaningful default: these are the fixed,
// out-of-bailiwick nameserver FQDNs this node advertises as every zone's own
// NS set. The placeholder values below are structurally correct but not a
// real, resolvable pair of nameservers; an operator deploying this build for
// real DNS service must change them (and this build must be rebuilt to
// change them, per the same no-env-var-configurability principle as
// everything else in this file).
func bind9ProductionConfig() bind9.Config {
	return bind9.Config{
		LiveDir:              "/etc/bind/lesta.d",
		StateRoot:            "/var/lib/lesta/bind",
		NamedConfPath:        "/etc/bind/named.conf",
		NamedCheckconfBinary: "named-checkconf",
		RndcBinary:           "rndc",
		Port:                 53,
		Nameservers:          []string{"ns1.lesta-hosting.example.", "ns2.lesta-hosting.example."},
	}
}
