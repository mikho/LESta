package daemon

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// TestPollAndDispatchFileOpsReportsResultWithoutWaitingForDispatch proves
// the one real behavior this fast lane exists for: pollAndDispatchFileOps
// itself returns immediately after kicking off a dispatch goroutine per
// operation, never blocking the caller (and so never blocking the next
// poll tick) on a slow Dispatch call -- the result still reaches the
// results endpoint, just asynchronously.
func TestPollAndDispatchFileOpsReportsResultWithoutWaitingForDispatch(t *testing.T) {
	resultReceived := make(chan protocol.ResultEnvelope, 1)

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/agent/v1/file-operations/poll":
			_ = json.NewEncoder(w).Encode(fileOpsPollResponse{
				PendingOperations: []protocol.OperationEnvelope{
					{
						ProtocolVersion: "1",
						Capability:      "files.manager.v1",
						Operation:       protocol.OperationObserve,
						ResourceID:      "res-1",
						IdempotencyKey:  "idem-1",
						CorrelationID:   "corr-1",
					},
				},
			})
		case "/agent/v1/operation-results":
			var body operationResultsRequest
			_ = json.NewDecoder(r.Body).Decode(&body)

			if len(body.Results) == 1 {
				resultReceived <- body.Results[0]
			}

			w.Write([]byte(`{"accepted":1}`))
		}
	}))
	defer server.Close()

	dispatchStarted := make(chan struct{})

	cfg := Config{
		ControlPlaneURL: server.URL,
		Dispatch: func(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
			close(dispatchStarted)
			// A deliberately slow dispatch: proves pollAndDispatchFileOps
			// itself already returned well before this completes.
			time.Sleep(50 * time.Millisecond)

			return protocol.ResultEnvelope{
				ProtocolVersion: op.ProtocolVersion,
				Capability:      op.Capability,
				ResourceID:      op.ResourceID,
				IdempotencyKey:  op.IdempotencyKey,
				CorrelationID:   op.CorrelationID,
				Status:          protocol.StatusApplied,
				Errors:          []protocol.ResultError{},
				CompletedAt:     time.Now().UTC(),
			}, nil
		},
	}

	pollStarted := time.Now()

	if err := pollAndDispatchFileOps(server.Client(), cfg, "credential"); err != nil {
		t.Fatalf("pollAndDispatchFileOps returned an error: %v", err)
	}

	if elapsed := time.Since(pollStarted); elapsed >= 50*time.Millisecond {
		t.Fatalf("pollAndDispatchFileOps took %v, expected it to return well before the 50ms slow dispatch completes", elapsed)
	}

	select {
	case <-dispatchStarted:
	case <-time.After(time.Second):
		t.Fatal("Dispatch was never called")
	}

	select {
	case result := <-resultReceived:
		if result.Status != protocol.StatusApplied {
			t.Fatalf("result status = %q, want %q", result.Status, protocol.StatusApplied)
		}
		if result.ResourceID != "res-1" {
			t.Fatalf("result resource_id = %q, want %q", result.ResourceID, "res-1")
		}
	case <-time.After(time.Second):
		t.Fatal("no result was ever reported to /agent/v1/operation-results")
	}
}

// TestFileOpsPollIntervalDefaultIsMuchTighterThanAHeartbeat documents and
// locks in the one real property this whole fast lane exists for: its own
// default poll interval is an order of magnitude tighter than a typical
// production heartbeat interval (60s, confirmed in
// cmd/lesta-agent/main.go's own readDaemonConfig default).
func TestFileOpsPollIntervalDefaultIsMuchTighterThanAHeartbeat(t *testing.T) {
	const typicalHeartbeatInterval = 60 * time.Second

	if defaultFileOpsPollInterval >= typicalHeartbeatInterval {
		t.Fatalf("defaultFileOpsPollInterval = %v, expected well under the typical %v heartbeat interval", defaultFileOpsPollInterval, typicalHeartbeatInterval)
	}
}
