package daemon

import (
	"context"
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sync/atomic"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/protocol"
)

func TestRunOneCycleSkipsExecutionReportWhenHeartbeatFails(t *testing.T) {
	var executionsCalled atomic.Bool

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/agent/v1/heartbeat":
			w.WriteHeader(http.StatusInternalServerError)
		case "/agent/v1/cron-executions":
			executionsCalled.Store(true)
			w.WriteHeader(http.StatusOK)
		}
	}))
	defer server.Close()

	dir := t.TempDir()
	writeExecutionLog(t, dir, "job-1", executionLogEntry{
		StartedAt: "2026-01-01T00:00:00Z", FinishedAt: "2026-01-01T00:00:01Z", ExitCode: 0, Output: "ok",
	})

	cfg := &Config{
		ControlPlaneURL:   server.URL,
		HeartbeatInterval: 60,
		CronStateRoot:     dir,
		WatermarkPath:     filepath.Join(dir, "watermark.json"),
	}

	if err := runOneCycle(server.Client(), cfg, "credential"); err == nil {
		t.Fatal("expected runOneCycle to return an error when the heartbeat itself fails")
	}

	if executionsCalled.Load() {
		t.Error("cron-executions endpoint was called despite the heartbeat failing")
	}
}

func TestRunOneCycleReportsExecutionsAfterSuccessfulHeartbeat(t *testing.T) {
	var executionsCalled atomic.Bool

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/agent/v1/heartbeat":
			w.Write([]byte(`{"ack":true,"next_heartbeat_seconds":60}`))
		case "/agent/v1/cron-executions":
			executionsCalled.Store(true)
			w.WriteHeader(http.StatusOK)
		}
	}))
	defer server.Close()

	dir := t.TempDir()
	writeExecutionLog(t, dir, "job-1", executionLogEntry{
		StartedAt: "2026-01-01T00:00:00Z", FinishedAt: "2026-01-01T00:00:01Z", ExitCode: 0, Output: "ok",
	})

	cfg := &Config{
		ControlPlaneURL:   server.URL,
		HeartbeatInterval: 60,
		CronStateRoot:     dir,
		WatermarkPath:     filepath.Join(dir, "watermark.json"),
	}

	if err := runOneCycle(server.Client(), cfg, "credential"); err != nil {
		t.Fatalf("runOneCycle returned error: %v", err)
	}

	if !executionsCalled.Load() {
		t.Error("cron-executions endpoint was never called despite a successful heartbeat")
	}
}

func TestRunOneCycleReportsOperationResultsAfterSuccessfulHeartbeat(t *testing.T) {
	var operationResultsCalled atomic.Bool

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/agent/v1/heartbeat":
			_, _ = w.Write([]byte(`{"ack":true,"next_heartbeat_seconds":60,"pending_operations":[{"protocol_version":"1","capability":"web.nginx.v1","operation":"create","resource_id":"11111111-1111-1111-1111-111111111111","desired_state_version":1,"idempotency_key":"22222222-2222-2222-2222-222222222222","correlation_id":"33333333-3333-3333-3333-333333333333","deadline":"2026-01-01T00:05:00Z","issued_at":"2026-01-01T00:00:00Z","request_digest":"sha256:0000000000000000000000000000000000000000000000000000000000000000","payload":{}}]}`))
		case "/agent/v1/cron-executions":
			w.WriteHeader(http.StatusOK)
		case "/agent/v1/operation-results":
			operationResultsCalled.Store(true)

			var body struct {
				Results []protocol.ResultEnvelope `json:"results"`
			}
			if err := json.NewDecoder(r.Body).Decode(&body); err != nil {
				t.Errorf("decoding operation-results body: %v", err)
			}

			if len(body.Results) != 1 {
				t.Errorf("got %d results, want 1", len(body.Results))
			}

			w.WriteHeader(http.StatusOK)
		}
	}))
	defer server.Close()

	dir := t.TempDir()

	cfg := &Config{
		ControlPlaneURL:   server.URL,
		HeartbeatInterval: 60,
		CronStateRoot:     dir,
		WatermarkPath:     filepath.Join(dir, "watermark.json"),
		Dispatch: func(ctx context.Context, op protocol.OperationEnvelope) (protocol.ResultEnvelope, error) {
			return protocol.ResultEnvelope{
				ProtocolVersion:      op.ProtocolVersion,
				Capability:           op.Capability,
				ResourceID:           op.ResourceID,
				IdempotencyKey:       op.IdempotencyKey,
				CorrelationID:        op.CorrelationID,
				Status:               protocol.StatusApplied,
				ObservedStateVersion: op.DesiredStateVersion,
				ObservedStateDigest:  "sha256:0000000000000000000000000000000000000000000000000000000000000000",
				GenerationID:         "gen-1",
				Errors:               []protocol.ResultError{},
				CompletedAt:          time.Now().UTC(),
			}, nil
		},
	}

	if err := runOneCycle(server.Client(), cfg, "credential"); err != nil {
		t.Fatalf("runOneCycle returned error: %v", err)
	}

	if !operationResultsCalled.Load() {
		t.Error("operation-results endpoint was never called despite a heartbeat carrying pending operations")
	}
}

func TestRunOneCycleSkipsOperationResultsWhenNoPendingOperations(t *testing.T) {
	var operationResultsCalled atomic.Bool

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		switch r.URL.Path {
		case "/agent/v1/heartbeat":
			w.Write([]byte(`{"ack":true,"next_heartbeat_seconds":60}`))
		case "/agent/v1/cron-executions":
			w.WriteHeader(http.StatusOK)
		case "/agent/v1/operation-results":
			operationResultsCalled.Store(true)
			w.WriteHeader(http.StatusOK)
		}
	}))
	defer server.Close()

	dir := t.TempDir()

	cfg := &Config{
		ControlPlaneURL:   server.URL,
		HeartbeatInterval: 60,
		CronStateRoot:     dir,
		WatermarkPath:     filepath.Join(dir, "watermark.json"),
	}

	if err := runOneCycle(server.Client(), cfg, "credential"); err != nil {
		t.Fatalf("runOneCycle returned error: %v", err)
	}

	if operationResultsCalled.Load() {
		t.Error("operation-results endpoint was called despite an empty pending_operations list")
	}
}

func TestBackoffGrowsOnFailureAndResetsOnSuccess(t *testing.T) {
	b := newBackoff()

	first := b.next()
	if first != backoffInitial {
		t.Errorf("first backoff = %v, want %v", first, backoffInitial)
	}

	second := b.next()
	if second <= first {
		t.Errorf("second backoff (%v) did not grow past the first (%v)", second, first)
	}

	b.reset()

	afterReset := b.next()
	if afterReset != backoffInitial {
		t.Errorf("backoff after reset = %v, want %v (back to initial)", afterReset, backoffInitial)
	}
}

func TestBackoffCapsAtMax(t *testing.T) {
	b := newBackoff()

	var last = b.next()
	for i := 0; i < 20; i++ {
		last = b.next()
	}

	if last > backoffMax {
		t.Errorf("backoff grew to %v, want capped at %v", last, backoffMax)
	}
}

func TestReadCredentialTrimsWhitespace(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "node-credential")

	if err := os.WriteFile(path, []byte("  abc123\n"), 0o600); err != nil {
		t.Fatalf("WriteFile: %v", err)
	}

	got, err := readCredential(path)
	if err != nil {
		t.Fatalf("readCredential returned error: %v", err)
	}

	if got != "abc123" {
		t.Errorf("readCredential() = %q, want %q", got, "abc123")
	}
}

func TestReadCredentialMissingFile(t *testing.T) {
	if _, err := readCredential("/nonexistent/node-credential"); err == nil {
		t.Fatal("expected an error for a missing credential file, got nil")
	}
}

func TestReadCredentialEmptyFile(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "node-credential")

	if err := os.WriteFile(path, []byte(""), 0o600); err != nil {
		t.Fatalf("WriteFile: %v", err)
	}

	if _, err := readCredential(path); err == nil {
		t.Fatal("expected an error for an empty credential file, got nil")
	}
}
