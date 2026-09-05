// Command lesta-agent is a one-shot stdin/stdout envelope pipe, not a daemon:
// it reads exactly one OperationEnvelope from stdin, applies it, and writes
// exactly one ResultEnvelope to stdout, then exits. This matches the phase's
// explicit scope: no network transport (HTTP/gRPC server, mTLS enrollment)
// exists yet between Laravel and a running agent; that is out of scope for
// this phase.
//
// Seven capabilities are wired up: web.nginx.v1, dns.bind9.v1, web.apache.v1,
// tls.acme.v1, database.tenant.v1, scheduler.account-cron.v1, and
// system.account-identity.v1.
//
// A third CLI mode, "daemon", is a genuinely long-running process (unlike
// the one-shot envelope pipe and the "cron-run" wrapper mode): it heartbeats
// this node's own liveness and capability presence to the control plane and
// reports cron execution history, over plain bearer-token auth against
// Laravel's own already-terminated HTTPS. See internal/daemon's own package
// doc comment.
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"os"
	"strings"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/acme"
	"github.com/mikho/LESta/agent/internal/capability/apache"
	"github.com/mikho/LESta/agent/internal/capability/bind9"
	"github.com/mikho/LESta/agent/internal/capability/cron"
	"github.com/mikho/LESta/agent/internal/capability/identity"
	"github.com/mikho/LESta/agent/internal/capability/mariadb"
	"github.com/mikho/LESta/agent/internal/capability/nginx"
	"github.com/mikho/LESta/agent/internal/daemon"
	"github.com/mikho/LESta/agent/internal/protocol"
)

const (
	webNginxCapability              = "web.nginx.v1"
	dnsBind9Capability              = "dns.bind9.v1"
	webApacheCapability             = "web.apache.v1"
	tlsAcmeCapability               = "tls.acme.v1"
	databaseTenantCapability        = "database.tenant.v1"
	schedulerCronCapability         = "scheduler.account-cron.v1"
	systemAccountIdentityCapability = "system.account-identity.v1"

	// webProfilePath is the one shared artifact both apache/install.sh and
	// nginx/install.sh's own --web-server both orchestration write: a single
	// trimmed line ("apache" or "both"). apacheProductionConfig reads it at
	// process start to pick Apache's own rendered-vhost listen port.
	webProfilePath = "/etc/lesta/web-profile"

	// agentVersion is this binary's own build version, reported verbatim in
	// every enrollment and heartbeat request. Bumped by hand alongside a
	// real release; not derived from git describe or similar, matching this
	// project's own preference for explicit, reviewable literals over
	// build-time magic.
	agentVersion = "1.0.0"
)

func main() {
	// "cron-run <resource_id> <run_as>" is a distinct CLI invocation shape,
	// never an OperationEnvelope read from stdin: this is the wrapper cron
	// itself execs on schedule (see internal/capability/cron's own package
	// doc comment), not a call from the Laravel-facing provisioning
	// pipeline. It must be checked before the normal run(os.Stdin,
	// os.Stdout) path, which never looks at os.Args at all. run_as is the
	// same value already named in the crontab line's own user-column (see
	// internal/capability/cron/capability.go's own renderFragment doc
	// comment on why it is repeated here as an explicit argument).
	if len(os.Args) >= 4 && os.Args[1] == "cron-run" {
		os.Exit(cron.RunJob(cronProductionConfig(), os.Args[2], os.Args[3]))
	}

	// "daemon" is a distinct, genuinely long-running CLI invocation shape,
	// never an OperationEnvelope read from stdin: this is the process
	// .install/lib/daemon.sh's own systemd unit execs and supervises, not a
	// call from the Laravel-facing provisioning pipeline.
	if len(os.Args) >= 2 && os.Args[1] == "daemon" {
		os.Exit(daemon.Run(daemonProductionConfig()))
	}

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

	result, err := dispatchOperation(context.Background(), op)
	if err != nil {
		return err
	}

	enc := json.NewEncoder(stdout)
	enc.SetIndent("", "  ")

	if err := enc.Encode(result); err != nil {
		return fmt.Errorf("encoding result envelope to stdout: %w", err)
	}

	return nil
}

// dispatchOperation selects the one capability op.Capability names and applies op against
// it. Shared by run's own one-shot stdin/stdout path and the daemon's own operations.go,
// so both routes to applying an OperationEnvelope pick a capability identically.
func dispatchOperation(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
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
	case schedulerCronCapability:
		capability = cron.New(cronProductionConfig())
	case systemAccountIdentityCapability:
		capability = identity.New(identityProductionConfig())
	default:
		return protocol.ResultEnvelope{}, fmt.Errorf("unsupported capability %q; this build only implements %q, %q, %q, %q, %q, %q, and %q", op.Capability, webNginxCapability, dnsBind9Capability, webApacheCapability, tlsAcmeCapability, databaseTenantCapability, schedulerCronCapability, systemAccountIdentityCapability)
	}

	result, err := capability.Apply(ctx, op)
	if err != nil {
		return protocol.ResultEnvelope{}, fmt.Errorf("applying operation: %w", err)
	}

	return result, nil
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

// apacheSSLPortForProfile maps a web-profile file's trimmed content to the
// port Apache's own rendered SSL vhosts should listen on: "both" (Apache is a
// loopback-only backend behind nginx, which owns the public 443 listener
// itself) gets 0 -- suppressing the SSL vhost's Listen entirely, since Apache
// must never bind 443 itself in that profile -- anything else, including a
// missing/unreadable file's empty string, keeps the safe default of 443
// (Apache is the public listener, matching a bare, non-"both" install).
func apacheSSLPortForProfile(profile string) int {
	if profile == "both" {
		return 0
	}

	return 443
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
// SSLPort is read the same way, via apacheSSLPortForProfile: 443 for a
// standalone apache profile, 0 (suppressing the SSL vhost's Listen
// entirely) for "both", where Apache must never bind 443 itself --
// nginx's own SSLPort in nginxProductionConfig owns that in the "both"
// profile. AcmeChallengeDir is the exact same literal path
// nginxProductionConfig's own AcmeChallengeDir resolves to (and
// acmeProductionConfig's own StateRoot+"/http-01" produces); both
// capabilities read this shared, hardcoded convention independently, the
// same way apache/install.sh and nginx/install.sh coordinate over
// /etc/lesta/web-profile.
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
		LiveDir:          "/etc/apache2/lesta.d",
		StateRoot:        "/var/lib/lesta/apache",
		ApacheConfPath:   "/etc/apache2/apache2.conf",
		ApacheBinary:     "apache2",
		Port:             apachePortForProfile(readWebProfile(webProfilePath)),
		SSLPort:          apacheSSLPortForProfile(readWebProfile(webProfilePath)),
		AcmeChallengeDir: "/var/lib/lesta/acme/http-01",
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

// cronProductionConfig points at the real, fixed host paths and identities
// .install/services/cron/install.sh establishes for scheduler.account-cron.v1,
// mirroring every other *ProductionConfig function's own non-configurability
// rationale: AgentBinaryPath is embedded verbatim into every crontab
// fragment's own scheduled line (see internal/capability/cron/capability.go's
// own renderFragment), so making it externally overridable would let
// anything able to set this process's environment redirect every future
// cron-run invocation to an arbitrary executable.
//
// AgentBinaryPath's literal value must stay in lockstep with
// .install/lib/agent.sh's own AGENT_BINARY_DEST and RunnerUser's literal
// value with .install/services/cron/install.sh's own creation of the
// lesta-cron system user.
func cronProductionConfig() cron.Config {
	return cron.Config{
		FragmentDir:     "/etc/cron.d",
		StateRoot:       "/var/lib/lesta/cron",
		RunnerUser:      "lesta-cron",
		AgentBinaryPath: "/var/lib/lesta/agent/bin/lesta-agent",
	}
}

// identityProductionConfig points at the real useradd/userdel/id binaries
// system.account-identity.v1 execs. Mirroring every other capability's own
// *Binary field: these are fixed, non-configurable literals (see
// internal/capability/identity/config.go's own Config doc comment), not
// environment overrides, since each is passed straight into an exec.Command
// call. There is no StateRoot here at all: unlike every other capability in
// this module, this one keeps no generation history or served file tree of
// its own (see internal/capability/identity's own package doc comment on
// why) -- its only state is the OS's own /etc/passwd, which useradd/userdel/
// id already own outright.
func identityProductionConfig() identity.Config {
	return identity.Config{
		UseraddBinary: "useradd",
		UserdelBinary: "userdel",
		IDBinary:      "id",
	}
}

// daemonProductionConfig points at the real, fixed host paths
// .install/services/agent-daemon/install.sh establishes for agent.daemon.v1.
// Unlike every capability's own *ProductionConfig function, there is no
// binary to guard against an environment-driven exec-target hijack here:
// this mode makes only outbound HTTP requests. What IS fixed for the same
// underlying reason (nothing about this daemon's own identity or reporting
// targets should be attacker-adjustable via environment) is ConfigPath and
// CredentialPath: both are root-owned, fixed locations the installer alone
// writes, read once at process start.
//
// ControlPlaneURL/NodeUUID/HeartbeatInterval/ProtocolVersion are read from
// ConfigPath (a small JSON file .install/lib/daemon.sh's own
// daemon_write_config writes at enrollment time), not hardcoded here,
// because unlike every other *ProductionConfig function's own literals
// (binaries, filesystem paths fixed by this project's own packaging
// convention), the control-plane URL and node identity are inherently
// per-deployment, per-node values with no single correct compiled-in
// default.
//
// CapabilityStateRoots's six literal values must stay in lockstep with
// nginxProductionConfig's/bind9ProductionConfig's/apacheProductionConfig's/
// acmeProductionConfig's/mariadbProductionConfig's/cronProductionConfig's
// own StateRoot fields above; this function never invokes those
// capabilities, it only os.Stats their fixed StateRoot to report presence.
func daemonProductionConfig() daemon.Config {
	const (
		configPath     = "/etc/lesta/agent/daemon-config.json"
		credentialPath = "/etc/lesta/agent/node-credential"
	)

	controlPlaneURL, nodeUUID, protocolVersion, heartbeatSeconds := readDaemonConfig(configPath)

	return daemon.Config{
		ControlPlaneURL:   controlPlaneURL,
		NodeUUID:          nodeUUID,
		CredentialPath:    credentialPath,
		ConfigPath:        configPath,
		HeartbeatInterval: time.Duration(heartbeatSeconds) * time.Second,
		ProtocolVersion:   protocolVersion,
		AgentVersion:      agentVersion,
		Dispatch:          dispatchOperation,
		CronStateRoot:     "/var/lib/lesta/cron",
		// /var/lib/lesta/agent itself is 0750 root:lesta; the daemon's own
		// lesta-agent identity is only ever a group member there, never the
		// owner, so its own writable watermark file must live in the nested
		// daemon-state subdirectory the installer creates specifically for
		// this (see .install/services/agent-daemon/install.sh).
		WatermarkPath: "/var/lib/lesta/agent/daemon-state/cron-execution-watermark.json",
		CapabilityStateRoots: map[string]string{
			webNginxCapability:       "/var/lib/lesta/nginx",
			dnsBind9Capability:       "/var/lib/lesta/bind",
			webApacheCapability:      "/var/lib/lesta/apache",
			tlsAcmeCapability:        "/var/lib/lesta/acme",
			databaseTenantCapability: "/var/lib/lesta/mariadb/tenant-agent-state",
			schedulerCronCapability:  "/var/lib/lesta/cron",
		},
	}
}

// readDaemonConfig reads and parses daemon-config.json, written once by
// .install/services/agent-daemon/install.sh's own daemon_write_config at
// enrollment time. Falls back to a 60 second heartbeat interval if the
// file's own value is missing or non-positive, the same safe default the
// installer itself writes.
func readDaemonConfig(path string) (controlPlaneURL, nodeUUID, protocolVersion string, heartbeatSeconds int) {
	heartbeatSeconds = 60

	raw, err := os.ReadFile(path)
	if err != nil {
		return "", "", "1", heartbeatSeconds
	}

	var parsed struct {
		ControlPlaneURL          string `json:"control_plane_url"`
		NodeUUID                 string `json:"node_uuid"`
		ProtocolVersion          string `json:"protocol_version"`
		HeartbeatIntervalSeconds int    `json:"heartbeat_interval_seconds"`
	}

	if err := json.Unmarshal(raw, &parsed); err != nil {
		return "", "", "1", heartbeatSeconds
	}

	if parsed.HeartbeatIntervalSeconds > 0 {
		heartbeatSeconds = parsed.HeartbeatIntervalSeconds
	}

	protocolVersion = parsed.ProtocolVersion
	if protocolVersion == "" {
		protocolVersion = "1"
	}

	return parsed.ControlPlaneURL, parsed.NodeUUID, protocolVersion, heartbeatSeconds
}
