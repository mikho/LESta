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

	if _, err := sendHeartbeat(server.Client(), cfg, "test-credential"); err != nil {
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

	if _, err := sendHeartbeat(server.Client(), cfg, "credential"); err != nil {
		t.Fatalf("sendHeartbeat returned error: %v", err)
	}

	if cfg.HeartbeatInterval != 60 {
		t.Errorf("HeartbeatInterval changed to %v despite an out-of-range server value", cfg.HeartbeatInterval)
	}
}

func TestSendHeartbeatReturnsPendingOperations(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.Header().Set("Content-Type", "application/json")
		_, _ = w.Write([]byte(`{"ack":true,"next_heartbeat_seconds":60,"pending_operations":[{"protocol_version":"1","capability":"web.nginx.v1","operation":"create","resource_id":"11111111-1111-1111-1111-111111111111","desired_state_version":1,"idempotency_key":"22222222-2222-2222-2222-222222222222","correlation_id":"33333333-3333-3333-3333-333333333333","deadline":"2026-01-01T00:05:00Z","issued_at":"2026-01-01T00:00:00Z","request_digest":"sha256:0000000000000000000000000000000000000000000000000000000000000000","payload":{}}]}`))
	}))
	defer server.Close()

	cfg := &Config{ControlPlaneURL: server.URL, HeartbeatInterval: 60}

	pending, err := sendHeartbeat(server.Client(), cfg, "credential")
	if err != nil {
		t.Fatalf("sendHeartbeat returned error: %v", err)
	}

	if len(pending) != 1 {
		t.Fatalf("got %d pending operations, want 1", len(pending))
	}

	if pending[0].IdempotencyKey != "22222222-2222-2222-2222-222222222222" {
		t.Errorf("IdempotencyKey = %q, want %q", pending[0].IdempotencyKey, "22222222-2222-2222-2222-222222222222")
	}
}

func TestSendHeartbeatServerErrorReturnsError(t *testing.T) {
	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer server.Close()

	cfg := &Config{ControlPlaneURL: server.URL, HeartbeatInterval: 60}

	if _, err := sendHeartbeat(server.Client(), cfg, "credential"); err == nil {
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

func TestCapabilityHealthReadsUnitStatesAndMarksADownServiceUnhealthy(t *testing.T) {
	dir := t.TempDir()

	for _, name := range []string{"nginx", "mail"} {
		if err := os.MkdirAll(filepath.Join(dir, name), 0o755); err != nil {
			t.Fatal(err)
		}
	}

	// A stand-in for systemctl that answers the way `systemctl show` does: one block per unit asked
	// for, in order, with a unit that does not exist reported as not-found.
	systemctl := filepath.Join(dir, "systemctl")
	script := `#!/bin/sh
printf 'Id=nginx.service\nLoadState=loaded\nActiveState=active\n\n'
printf 'Id=exim4.service\nLoadState=loaded\nActiveState=active\n\n'
printf 'Id=dovecot.service\nLoadState=loaded\nActiveState=failed\n\n'
printf 'Id=lesta-applier.service\nLoadState=loaded\nActiveState=active\n\n'
printf 'Id=apache2.service\nLoadState=not-found\nActiveState=inactive\n'
`
	if err := os.WriteFile(systemctl, []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}

	cfg := &Config{
		CapabilityStateRoots: map[string]string{"web.nginx.v1": filepath.Join(dir, "nginx"), "mail.smtp-imap.v1": filepath.Join(dir, "mail"), "web.apache.v1": filepath.Join(dir, "absent")},
		CapabilityUnits:      map[string][]string{"web.nginx.v1": {"nginx"}, "mail.smtp-imap.v1": {"exim4", "dovecot"}, "web.apache.v1": {"apache2"}},
		CoreUnits:            []string{"lesta-applier", "apache2"},
		SystemctlBinary:      systemctl,
	}

	capabilities, services := capabilityHealth(cfg)

	health := map[string]string{}
	for _, c := range capabilities {
		health[c.Capability] = c.HealthState
	}

	if health["web.nginx.v1"] != "healthy" || health["mail.smtp-imap.v1"] != "unhealthy" || len(health) != 2 {
		t.Errorf("capability health = %v, want nginx healthy, mail unhealthy, nothing else", health)
	}

	got := map[string]string{}
	for _, s := range services {
		got[s.Name] = s.State
	}

	want := map[string]string{"nginx": "active", "exim4": "active", "dovecot": "failed", "lesta-applier": "active"}
	if len(got) != len(want) {
		t.Fatalf("services = %v, want %v (a unit that does not exist is left out)", got, want)
	}

	for name, state := range want {
		if got[name] != state {
			t.Errorf("service %s = %q, want %q", name, got[name], state)
		}
	}
}

func TestCapabilityHealthWithoutSystemdKeepsPresenceAndReportsNoServices(t *testing.T) {
	dir := t.TempDir()
	if err := os.MkdirAll(filepath.Join(dir, "nginx"), 0o755); err != nil {
		t.Fatal(err)
	}

	cfg := &Config{
		CapabilityStateRoots: map[string]string{"web.nginx.v1": filepath.Join(dir, "nginx")},
		CapabilityUnits:      map[string][]string{"web.nginx.v1": {"nginx"}},
		SystemctlBinary:      filepath.Join(dir, "no-such-systemctl"),
	}

	capabilities, services := capabilityHealth(cfg)

	if len(capabilities) != 1 || capabilities[0].HealthState != "healthy" || services != nil {
		t.Errorf("capabilities %+v services %+v, want present-means-healthy and no services", capabilities, services)
	}
}
