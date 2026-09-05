package daemon

import (
	"fmt"
	"log"
	"net/http"
	"os"
	"strings"
	"time"
)

// Run starts the daemon's own infinite heartbeat loop, returning only on an
// unrecoverable startup error (e.g. no credential file). Intended to be the
// entire body of the "lesta-agent daemon" process; its return value is used
// directly as the process's own exit code by cmd/lesta-agent/main.go.
func Run(cfg Config) int {
	credential, err := readCredential(cfg.CredentialPath)
	if err != nil {
		fmt.Fprintln(os.Stderr, "lesta-agent daemon:", err)

		return 1
	}

	client := &http.Client{Timeout: 30 * time.Second}
	b := newBackoff()

	for {
		interval := cfg.HeartbeatInterval

		if err := runOneCycle(client, &cfg, credential); err != nil {
			log.Printf("heartbeat cycle failed: %v", err)

			interval = b.next()
		} else {
			b.reset()
		}

		time.Sleep(jitter(interval))
	}
}

// runOneCycle sends one heartbeat, then, only if that succeeds, reports any
// new cron execution-history entries and applies+reports any pending
// provisioning operations the heartbeat carried back. A failed heartbeat is
// a hard error that skips both reports for this cycle entirely.
func runOneCycle(client *http.Client, cfg *Config, credential string) error {
	pending, err := sendHeartbeat(client, cfg, credential)
	if err != nil {
		return fmt.Errorf("heartbeat: %w", err)
	}

	if err := reportCronExecutions(client, *cfg, credential); err != nil {
		return fmt.Errorf("cron execution report: %w", err)
	}

	if len(pending) > 0 {
		if err := reportOperationResults(client, *cfg, credential, pending); err != nil {
			return fmt.Errorf("operation result report: %w", err)
		}
	}

	return nil
}

// readCredential reads and trims the raw bearer token from path.
func readCredential(path string) (string, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		return "", fmt.Errorf("reading node credential from %s: %w", path, err)
	}

	credential := strings.TrimSpace(string(raw))
	if credential == "" {
		return "", fmt.Errorf("node credential file %s is empty", path)
	}

	return credential, nil
}
