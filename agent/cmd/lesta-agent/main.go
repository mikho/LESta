// Command lesta-agent is a one-shot stdin/stdout envelope pipe, not a daemon:
// it reads exactly one OperationEnvelope from stdin, applies it, and writes
// exactly one ResultEnvelope to stdout, then exits. This matches the phase's
// explicit scope: no network transport (HTTP/gRPC server, mTLS enrollment)
// exists yet between Laravel and a running agent; that is out of scope for
// this phase.
//
// Five capabilities are wired up: web.nginx.v1, dns.bind9.v1, web.apache.v1,
// tls.acme.v1, and database.tenant.v1.
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"strings"

	"github.com/mikho/LESta/agent/internal/capability/acme"
	"github.com/mikho/LESta/agent/internal/capability/apache"
	"github.com/mikho/LESta/agent/internal/capability/bind9"
	"github.com/mikho/LESta/agent/internal/capability/mariadb"
	"github.com/mikho/LESta/agent/internal/capability/nginx"
	"github.com/mikho/LESta/agent/internal/protocol"
)

const (
	webNginxCapability       = "web.nginx.v1"
	dnsBind9Capability       = "dns.bind9.v1"
	webApacheCapability      = "web.apache.v1"
	tlsAcmeCapability        = "tls.acme.v1"
	databaseTenantCapability = "database.tenant.v1"

	// webProfilePath is the one shared artifact both apache/install.sh and
	// nginx/install.sh's own --web-server both orchestration write: a single
	// trimmed line ("apache" or "both"). apacheProductionConfig reads it at
	// process start to pick Apache's own rendered-vhost listen port.
	webProfilePath = "/etc/lesta/web-profile"
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
	case tlsAcmeCapability:
		capability = acme.New(acmeProductionConfig())
	case databaseTenantCapability:
		capability = mariadb.New(mariadbProductionConfig())
	default:
		return fmt.Errorf("unsupported capability %q; this build only implements %q, %q, %q, %q, and %q", op.Capability, webNginxCapability, dnsBind9Capability, webApacheCapability, tlsAcmeCapability, databaseTenantCapability)
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
		// ProxyBackend: the fixed loopback address+port Apache listens on in
		// the "both" web profile (see apacheProductionConfig's own
		// apachePortForProfile), matching
		// .install/profiles/schema.json's own hardcoded backend port.
		// Harmless when unused (every web_template other than "apache-proxy"
		// never references it).
		ProxyBackend: "127.0.0.1:8080",
		// AcmeChallengeDir: the exact same path acmeProductionConfig's own
		// StateRoot+"/http-01" resolves to. Both this process's own
		// tls.acme.v1 dispatch (when invoked separately, for that
		// capability) and this nginx dispatch read this literal string
		// independently; there is no runtime negotiation between the two
		// capabilities, only this shared, hardcoded convention, the same
		// way apache/install.sh and nginx/install.sh coordinate over
		// /etc/lesta/web-profile.
		AcmeChallengeDir: "/var/lib/lesta/acme/http-01",
		// SSLPort: 443, the standard HTTPS port. Only ever referenced by a
		// rendered vhost once WebDomain has a real issued certificate (see
		// payload.go's own SSL.CertificatePath doc comment).
		SSLPort: 443,
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

// readWebProfile reads and trims webProfilePath's content, returning "" if
// the file is missing or unreadable -- the safe default, per
// apachePortForProfile: a node that was never bootstrapped through the
// "both" profile (or whose web-profile file is absent for any other reason)
// must never be treated as one.
func readWebProfile(path string) string {
	raw, err := os.ReadFile(path)
	if err != nil {
		return ""
	}

	return strings.TrimSpace(string(raw))
}

// apachePortForProfile maps a web-profile file's trimmed content to the port
// Apache's own rendered vhosts should listen on: "both" (Apache is a
// loopback-only backend behind nginx) gets 8080; anything else, including a
// missing/unreadable file's empty string, keeps the safe default of 80
// (Apache is the public listener, matching a bare, non-"both" install).
func apachePortForProfile(profile string) int {
	if profile == "both" {
		return 8080
	}

	return 80
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
// Port is read from webProfilePath at call time via
// apachePortForProfile(readWebProfile(...)): "both" (the loopback-backend
// profile) selects 8080, matching nginxProductionConfig's own hardcoded
// ProxyBackend; anything else (including a missing file, the safe default)
// keeps 80, Apache as the public listener. apache/install.sh and
// nginx/install.sh's own --web-server both orchestration are the only
// writers of that file; this function is the one and only place the
// profile-to-port mapping lives, and it is re-read on every process start
// (this binary is a one-shot stdin/stdout pipe, not a daemon, so "at call
// time" means "at every invocation").
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
		Port:           apachePortForProfile(readWebProfile(webProfilePath)),
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

// acmeProductionConfig points at the real, fixed host path
// .install/services/acme/manifest.json's own owned_roots declares
// (/var/lib/lesta/acme). Unlike nginxProductionConfig/bind9ProductionConfig/
// apacheProductionConfig, there is no binary or env list to guard here at
// all: tls.acme.v1 never execs anything (see internal/capability/acme's own
// package doc comment), it only ever reads and writes plain files under this
// one root, so StateRoot is the only production value this capability needs.
func acmeProductionConfig() acme.Config {
	return acme.Config{
		StateRoot: "/var/lib/lesta/acme",
	}
}

// mariadbProductionConfig points at the real, fixed connection details and
// paths .install/services/mariadb/install.sh establishes for the tenant
// MariaDB instance (port 3307; control-plane MariaDB on 3306 is a separate,
// out-of-scope instance this capability never touches). MariaDBBinary is
// passed straight into an exec.Command call (see
// internal/capability/mariadb/exec.go's own runSQL), so -- mirroring every
// other capability's own *Binary field -- it is not configurable via
// environment variables or flags.
//
// DefaultsExtraFile points at the root-owned, 0600 ini install.sh writes
// carrying this capability's own dedicated admin account's credentials (see
// internal/capability/mariadb/config.go's own Config.DefaultsExtraFile doc
// comment for why this is the standard MariaDB mechanism for keeping
// credentials out of argv/env/`ps`, not a convenience). StateRoot is this
// capability's own generation-history bookkeeping root, deliberately
// distinct from the MariaDB server's own datadir (/var/lib/lesta/mariadb/
// tenant, owned and written by mariadbd itself as the `mysql` system user,
// never by this capability).
func mariadbProductionConfig() mariadb.Config {
	return mariadb.Config{
		Host:              "127.0.0.1",
		Port:              3307,
		MariaDBBinary:     "mariadb",
		DefaultsExtraFile: "/etc/lesta/mariadb-tenant-admin.cnf",
		StateRoot:         "/var/lib/lesta/mariadb/tenant-agent-state",
	}
}
