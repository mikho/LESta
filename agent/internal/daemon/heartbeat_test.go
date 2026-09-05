package daemon

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
)

func TestSendHeartbeatSuccessAppliesNextHeartbeatSeconds(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		if got := r.Header.Get("Authorization"); got != "Bearer test-credential" {
			t.Errorf("Authorization header = %q, want %q", got, "Bearer test-credential")
		}

		var req heartbeatRequest
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			t.Fatalf("decoding request body: %v", err)
		}

		if req.NodeUUID != "node-1" {
			t.Errorf("NodeUUID = %q, want %q", req.NodeUUID, "node-1")
		}

		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"ack":true,"next_heartbeat_seconds":120}`))
	}))
	defer server.Close()

	cfg := &Config{
		ControlPlaneURL:   server.URL,
		NodeUUID:          "node-1",
		ProtocolVersion:   "1",
		AgentVersion:      "1.0.0",
		HeartbeatInterval: 60,
	}

	if err := sendHeartbeat(server.Client(), cfg, "test-credential"); err != nil {
		t.Fatalf("sendHeartbeat returned error: %v", err)
	}

	if cfg.HeartbeatInterval.Seconds() != 120 {
		t.Errorf("HeartbeatInterval = %v, want 120s", cfg.HeartbeatInterval)
	}
}

func TestSendHeartbeatIgnoresAbsurdNextHeartbeatSeconds(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		_, _ = w.Write([]byte(`{"ack":true,"next_heartbeat_seconds":999999}`))
	}))
	defer server.Close()

	cfg := &Config{ControlPlaneURL: server.URL, HeartbeatInterval: 60}

	if err := sendHeartbeat(server.Client(), cfg, "credential"); err != nil {
		t.Fatalf("sendHeartbeat returned error: %v", err)
	}

	if cfg.HeartbeatInterval != 60 {
		t.Errorf("HeartbeatInterval changed to %v despite an out-of-range server value", cfg.HeartbeatInterval)
	}
}

func TestSendHeartbeatServerErrorReturnsError(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer server.Close()

	cfg := &Config{ControlPlaneURL: server.URL, HeartbeatInterval: 60}

	if err := sendHeartbeat(server.Client(), cfg, "credential"); err == nil {
		t.Fatal("expected an error for a 500 response, got nil")
	}
}

func TestPresentCapabilitiesOmitsMissingPaths(t *testing.T) {
	dir := t.TempDir()

	present := filepath.Join(dir, "present")
	if err := os.MkdirAll(present, 0o755); err != nil {
		t.Fatalf("MkdirAll: %v", err)
	}

	missing := filepath.Join(dir, "missing")

	statuses := presentCapabilities(map[string]string{
		"web.nginx.v1": present,
		"dns.bind9.v1": missing,
	})

	if len(statuses) != 1 {
		t.Fatalf("presentCapabilities returned %d entries, want 1: %+v", len(statuses), statuses)
	}

	if statuses[0].Capability != "web.nginx.v1" || statuses[0].HealthState != "healthy" {
		t.Errorf("unexpected status: %+v", statuses[0])
	}
}

func TestUbuntuRelease(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "os-release")

	content := "NAME=\"Ubuntu\"\nVERSION_ID=\"24.04\"\nID=ubuntu\n"
	if err := os.WriteFile(path, []byte(content), 0o644); err != nil {
		t.Fatalf("WriteFile: %v", err)
	}

	if got := ubuntuRelease(path); got != "24.04" {
		t.Errorf("ubuntuRelease() = %q, want %q", got, "24.04")
	}
}

func TestUbuntuReleaseMissingFile(t *testing.T) {
	if got := ubuntuRelease("/nonexistent/os-release"); got != "" {
		t.Errorf("ubuntuRelease() = %q, want empty string for a missing file", got)
	}
}
