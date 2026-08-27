// Command lesta-agent is a one-shot stdin/stdout envelope pipe, not a daemon:
// it reads exactly one OperationEnvelope from stdin, applies it, and writes
// exactly one ResultEnvelope to stdout, then exits. This matches the phase's
// explicit scope: no network transport (HTTP/gRPC server, mTLS enrollment)
// exists yet between Laravel and a running agent; that is out of scope for
// this phase.
//
// Only the web.nginx.v1 capability is wired up: it is the only capability this
// phase builds (no Apache, no combined "both" web profile).
package main

import (
	"context"
	"encoding/json"
	"fmt"
	"os"

	"github.com/mikho/LESta/agent/internal/capability/nginx"
	"github.com/mikho/LESta/agent/internal/protocol"
)

const webNginxCapability = "web.nginx.v1"

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

	if op.Capability != webNginxCapability {
		return fmt.Errorf("unsupported capability %q; this build only implements %q", op.Capability, webNginxCapability)
	}

	capability := nginx.New(productionConfig())

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

// productionConfig points at the real, fixed host paths this phase's own ADR
// reconciliation settled on. Creating these paths (and nginx.conf's own
// `include /etc/nginx/lesta.d/*.conf;` line) is a bootstrap-installer
// precondition this phase's code requires, not something it creates itself.
func productionConfig() nginx.Config {
	return nginx.Config{
		LiveDir:       "/etc/nginx/lesta.d",
		StateRoot:     "/var/lib/lesta/nginx",
		NginxConfPath: "/etc/nginx/nginx.conf",
		NginxBinary:   "nginx",
		Port:          80,
	}
}
