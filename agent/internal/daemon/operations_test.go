package daemon

import (
	"context"
	"encoding/json"
	"errors"
	"net/http"
	"net/http/httptest"
	"strings"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/protocol"
)

func testEnvelope(idempotencyKey string) protocol.OperationEnvelope {
	return protocol.OperationEnvelope{
		ProtocolVersion:     "1",
		Capability:          "web.nginx.v1",
		Operation:           protocol.OperationCreate,
		ResourceID:          "11111111-1111-1111-1111-111111111111",
		DesiredStateVersion: 1,
		IdempotencyKey:      idempotencyKey,
		CorrelationID:       "22222222-2222-2222-2222-222222222222",
		Deadline:            time.Now().Add(5 * time.Minute),
		IssuedAt:            time.Now(),
		RequestDigest:       "sha256:" + strings.Repeat("0", 64),
		Payload:             json.RawMessage(`{}`),
	}
}

func TestReportOperationResultsPostsDispatchedResult(t *testing.T) {
	var received operationResultsRequest

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if err := json.NewDecoder(r.Body).Decode(&received); err != nil {
			t.Fatalf("decoding request body: %v", err)
		}

		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	envelope := testEnvelope("33333333-3333-3333-3333-333333333333")

	cfg := Config{
		ControlPlaneURL: server.URL,
		Dispatch: func(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
			return protocol.ResultEnvelope{
				ProtocolVersion:      op.ProtocolVersion,
				Capability:           op.Capability,
				ResourceID:           op.ResourceID,
				IdempotencyKey:       op.IdempotencyKey,
				CorrelationID:        op.CorrelationID,
				Status:               protocol.StatusApplied,
				ObservedStateVersion: op.DesiredStateVersion,
				ObservedStateDigest:  "sha256:" + strings.Repeat("1", 64),
				GenerationID:         "gen-1",
				Errors:               []protocol.ResultError{},
				CompletedAt:          time.Now().UTC(),
			}, nil
		},
	}

	if err := reportOperationResults(server.Client(), cfg, "credential", []protocol.OperationEnvelope{envelope}); err != nil {
		t.Fatalf("reportOperationResults returned error: %v", err)
	}

	if len(received.Results) != 1 {
		t.Fatalf("got %d results, want 1", len(received.Results))
	}

	if received.Results[0].IdempotencyKey != envelope.IdempotencyKey {
		t.Errorf("IdempotencyKey = %q, want %q", received.Results[0].IdempotencyKey, envelope.IdempotencyKey)
	}

	if received.Results[0].Status != protocol.StatusApplied {
		t.Errorf("Status = %q, want %q", received.Results[0].Status, protocol.StatusApplied)
	}
}

func TestReportOperationResultsSendsSyntheticFailedResultWhenDispatchErrors(t *testing.T) {
	var received operationResultsRequest

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if err := json.NewDecoder(r.Body).Decode(&received); err != nil {
			t.Fatalf("decoding request body: %v", err)
		}

		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	envelope := testEnvelope("44444444-4444-4444-4444-444444444444")

	cfg := Config{
		ControlPlaneURL: server.URL,
		Dispatch: func(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
			return protocol.ResultEnvelope{}, errors.New("boom")
		},
	}

	if err := reportOperationResults(server.Client(), cfg, "credential", []protocol.OperationEnvelope{envelope}); err != nil {
		t.Fatalf("reportOperationResults returned error: %v", err)
	}

	if len(received.Results) != 1 {
		t.Fatalf("got %d results, want 1", len(received.Results))
	}

	result := received.Results[0]

	if result.Status != protocol.StatusFailed {
		t.Errorf("Status = %q, want %q", result.Status, protocol.StatusFailed)
	}

	if result.IdempotencyKey != envelope.IdempotencyKey {
		t.Errorf("IdempotencyKey = %q, want %q", result.IdempotencyKey, envelope.IdempotencyKey)
	}

	if len(result.Errors) != 1 || result.Errors[0].Code != "dispatch_failed" {
		t.Errorf("Errors = %+v, want one dispatch_failed entry", result.Errors)
	}
}

// An empty pending_operations list never reaching reportOperationResults at all is
// runOneCycle's own responsibility (it gates the call on len(pending) > 0), covered by
// TestRunOneCycleSkipsOperationResultsWhenNoPendingOperations in daemon_test.go.
