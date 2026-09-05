package daemon

import (
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"sync/atomic"
	"testing"
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
