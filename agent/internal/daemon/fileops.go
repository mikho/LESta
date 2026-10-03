package daemon

import (
	"context"
	"encoding/json"
	"fmt"
	"io"
	"log"
	"net/http"
	"time"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// defaultFileOpsPollInterval is used whenever Config.FileOpsPollInterval is
// zero or negative.
const defaultFileOpsPollInterval = 2 * time.Second

// runFileOpsLane runs forever, started as its own goroutine by Run
// alongside the general heartbeat loop: polls a dedicated, much tighter
// endpoint than the general heartbeat for pending files.manager.v1
// operations, dispatches each one in its own goroutine, and reports it
// back the moment it completes -- never batched, and never waiting on the
// general heartbeat's own tens-of-seconds cadence, which an interactive
// file browser's list/read/write/rename/delete UX cannot tolerate. Every
// operation still becomes a real, audited ProvisioningOperation row on the
// control plane, identical bookkeeping to every other capability; this is
// purely an additional, faster delivery path for one specific capability,
// never a replacement for the general heartbeat loop.
func runFileOpsLane(client *http.Client, cfg Config, credential string) {
	interval := cfg.FileOpsPollInterval
	if interval <= 0 {
		interval = defaultFileOpsPollInterval
	}

	for {
		if err := pollAndDispatchFileOps(client, cfg, credential); err != nil {
			log.Printf("file-ops poll cycle failed: %v", err)
		}

		time.Sleep(interval)
	}
}

// fileOpsPollResponse is the JSON body
// <ControlPlaneURL>/agent/v1/file-operations/poll returns.
type fileOpsPollResponse struct {
	PendingOperations []protocol.OperationEnvelope `json:"pending_operations"`
}

// pollAndDispatchFileOps polls once, then dispatches and reports each
// returned operation concurrently (one goroutine per operation, so a slow
// one -- a large file read, say -- never delays another's own completion
// or the next poll tick). Each goroutine reports its own single-element
// results batch immediately via the exact same postOperationResults
// (operations.go) the general heartbeat loop already uses: the control
// plane's own result-ingestion endpoint is entirely capability-agnostic,
// so no separate results endpoint or handler is needed for this lane.
func pollAndDispatchFileOps(client *http.Client, cfg Config, credential string) error {
	pending, err := pollFileOps(client, cfg, credential)
	if err != nil {
		return fmt.Errorf("file-ops poll: %w", err)
	}

	for _, envelope := range pending {
		go func(envelope protocol.OperationEnvelope) {
			result, err := cfg.Dispatch(context.Background(), envelope)
			if err != nil {
				result = syntheticFailedResult(envelope, err)
			}

			if err := postOperationResults(client, cfg, credential, []protocol.ResultEnvelope{result}); err != nil {
				log.Printf("file-ops result report failed for resource %s: %v", envelope.ResourceID, err)
			}
		}(envelope)
	}

	return nil
}

// pollFileOps POSTs one poll request. A non-2xx response or network error
// is returned as an error; an unparseable 2xx body is tolerated (returns
// no pending operations rather than failing the cycle), matching
// sendHeartbeat's own leniency.
func pollFileOps(client *http.Client, cfg Config, credential string) ([]protocol.OperationEnvelope, error) {
	httpReq, err := http.NewRequest(http.MethodPost, cfg.ControlPlaneURL+"/agent/v1/file-operations/poll", nil)
	if err != nil {
		return nil, fmt.Errorf("building file-ops poll request: %w", err)
	}

	httpReq.Header.Set("Authorization", "Bearer "+credential)

	resp, err := client.Do(httpReq)
	if err != nil {
		return nil, fmt.Errorf("sending file-ops poll request: %w", err)
	}
	defer resp.Body.Close()

	respBody, _ := io.ReadAll(resp.Body)

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, fmt.Errorf("file-ops poll request returned status %d: %s", resp.StatusCode, string(respBody))
	}

	var parsed fileOpsPollResponse
	if err := json.Unmarshal(respBody, &parsed); err != nil {
		return nil, nil
	}

	return parsed.PendingOperations, nil
}
