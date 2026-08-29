// Command lesta-agent is a one-shot stdin/stdout envelope pipe, not a daemon:
// it reads exactly one OperationEnvelope from stdin, applies it, and writes
// exactly one ResultEnvelope to stdout, then exits. This matches the phase's
// explicit scope: no network transport (HTTP/gRPC server, mTLS enrollment)
// exists yet between Laravel and a running agent; that is out of scope for
// this phase.
//
// Three capabilities are wired up: web.nginx.v1, dns.bind9.v1, and
// web.apache.v1.
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"os"

	"github.com/mikho/LESta/agent/internal/capability/apache"
	"github.com/mikho/LESta/agent/internal/capability/bind9"
	"github.com/mikho/LESta/agent/internal/capability/nginx"
	"github.com/mikho/LESta/agent/internal/protocol"
)

const (
	webNginxCapability  = "web.nginx.v1"
	dnsBind9Capability  = "dns.bind9.v1"
	webApacheCapability = "web.apache.v1"
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
	case webApacheCapability:
		capability = apache.New(apacheProductionConfig())
	default:
		return fmt.Errorf("unsupported capability %q; this build only implements %q, %q, and %q", op.Capability, webNginxCapability, dnsBind9Capability, webApacheCapability)
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

// apacheProductionConfig points at the real, fixed host paths and binary
// this phase's own plan settled on for web.apache.v1, mirroring
// nginxProductionConfig's and bind9ProductionConfig's own
// non-configurability rationale exactly: ApacheBinary is passed straight
// into exec.Command calls (see internal/capability/apache/validate.go and
// reload.go), so making it externally overridable would let anything able to
// set this process's environment redirect a privileged exec to an arbitrary
// executable.
//
// Env's six values are a researched reconstruction of Ubuntu/Debian's real
// /etc/apache2/envvars defaults (APACHE_RUN_USER/APACHE_RUN_GROUP=www-data,
// the standard pid/run/lock/log directories under /var/run and /var/log),
// which apache2ctl normally sources before exec'ing the real apache2 binary.
// Since this package execs apache2 directly, bypassing that wrapper script
// entirely (the same bypass nginxProductionConfig already makes for nginx's
// own service script), it must supply the same fixed values itself. These
// six literals are NOT yet hands-on-confirmed against a real Ubuntu box by
// this phase's own work (only researched against Debian/Ubuntu apache2
// packaging convention); the disposable test harness this phase's own test
// suite actually exercises never uses this function at all (it builds its
// own apache.Config with an empty Env, since its from-scratch synthetic
// config never references ${APACHE_...}), so CI's real-apache2 job is the
// first real exercise of these exact values and should be treated as the
// verification of this comment's own claim, not this comment itself.
func apacheProductionConfig() apache.Config {
	return apache.Config{
		LiveDir:        "/etc/apache2/lesta.d",
		StateRoot:      "/var/lib/lesta/apache",
		ApacheConfPath: "/etc/apache2/apache2.conf",
		ApacheBinary:   "apache2",
		Port:           80,
		Env: []string{
			"APACHE_RUN_USER=www-data",
			"APACHE_RUN_GROUP=www-data",
			"APACHE_PID_FILE=/var/run/apache2/apache2.pid",
			"APACHE_RUN_DIR=/var/run/apache2",
			"APACHE_LOCK_DIR=/var/lock/apache2",
			"APACHE_LOG_DIR=/var/log/apache2",
		},
	}
}
