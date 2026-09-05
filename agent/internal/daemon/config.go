// Package daemon implements the "lesta-agent daemon" long-running mode: a
// heartbeat loop that reports this node's own liveness and capability
// presence to the control plane, plus a cron execution-history reporter
// that tails each capability's own execution-log files and forwards new
// entries. Unlike every capability under internal/capability, this package
// never applies an OperationEnvelope and never touches the six existing
// provisioning capabilities' own state beyond reading (never writing) their
// fixed StateRoot paths to check presence.
package daemon

import "time"

// Config parameterizes Run by the fixed paths, identity, and network
// details this daemon needs, so the identical implementation runs against
// production's real fixed paths (see cmd/lesta-agent/main.go's own
// daemonProductionConfig) or a disposable per-test temp directory plus an
// httptest.Server standing in for the control plane.
type Config struct {
	// ControlPlaneURL is the base URL heartbeat and cron-execution requests
	// are POSTed against, e.g. "https://panel.example". Never includes a
	// trailing slash.
	ControlPlaneURL string
	// NodeUUID identifies this node to the control plane; carried in every
	// request body (the bearer credential alone already authenticates the
	// node, but the body's own node_uuid lets the control plane's request
	// logs and validation stay explicit rather than implicit).
	NodeUUID string
	// CredentialPath is the absolute path to a 0600 file containing the raw
	// bearer token this node was issued at enrollment (production:
	// /etc/lesta/agent/node-credential). Read once at process start and
	// held in memory for the life of this process; never re-read on every
	// request.
	CredentialPath string
	// ConfigPath is the absolute path to the JSON file the installer wrote
	// at enrollment time carrying ControlPlaneURL/NodeUUID/HeartbeatInterval/
	// ProtocolVersion (production: /etc/lesta/agent/daemon-config.json).
	// Only ever read by daemonProductionConfig in cmd/lesta-agent/main.go,
	// never by this package directly.
	ConfigPath string
	// HeartbeatInterval is how long to sleep between heartbeat cycles. May
	// be adjusted at runtime by a heartbeat response's own
	// next_heartbeat_seconds field (clamped to a sane range), and is backed
	// off further on failure.
	HeartbeatInterval time.Duration
	// ProtocolVersion and AgentVersion are reported verbatim in every
	// heartbeat request body.
	ProtocolVersion string
	AgentVersion    string
	// CronStateRoot is scheduler.account-cron.v1's own StateRoot (production:
	// /var/lib/lesta/cron); this package only ever reads
	// CronStateRoot/executions/*.log, matching runner.go's own log format,
	// never any other file under this capability's own state root.
	CronStateRoot string
	// WatermarkPath is where this package persists, per resource_id, the
	// byte offset already successfully reported to the control plane
	// (production: /var/lib/lesta/agent/cron-execution-watermark.json).
	WatermarkPath string
	// CapabilityStateRoots maps each of the six known capability strings to
	// the fixed production StateRoot path cmd/lesta-agent/main.go's own
	// *ProductionConfig functions already hardcode. Used only to check
	// presence (os.Stat on the directory), never to invoke that
	// capability's own Apply/Observe.
	CapabilityStateRoots map[string]string
}
