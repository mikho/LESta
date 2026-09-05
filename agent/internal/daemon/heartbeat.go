package daemon

import (
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"runtime"
	"strings"
	"time"

	"github.com/mikho/LESta/agent/internal/protocol"
)

const (
	// minHeartbeatSeconds/maxHeartbeatSeconds clamp a heartbeat response's
	// own next_heartbeat_seconds field, so the control plane can never push
	// this daemon into an absurdly tight or absurdly slow loop.
	minHeartbeatSeconds = 10
	maxHeartbeatSeconds = 3600

	osReleasePath = "/etc/os-release"
)

// heartbeatRequest is the JSON body POSTed to <ControlPlaneURL>/agent/v1/heartbeat.
type heartbeatRequest struct {
	ProtocolVersion string             `json:"protocol_version"`
	NodeUUID        string             `json:"node_uuid"`
	AgentVersion    string             `json:"agent_version"`
	UbuntuRelease   string             `json:"ubuntu_release"`
	Architecture    string             `json:"architecture"`
	Capabilities    []capabilityStatus `json:"capabilities"`
	Timestamp       string             `json:"timestamp"`
}

type capabilityStatus struct {
	Capability  string `json:"capability"`
	HealthState string `json:"health_state"`
}

// heartbeatResponse is the JSON body a successful (2xx) heartbeat returns.
type heartbeatResponse struct {
	Ack                  bool                         `json:"ack"`
	NextHeartbeatSeconds int                          `json:"next_heartbeat_seconds"`
	PendingOperations    []protocol.OperationEnvelope `json:"pending_operations"`
}

// sendHeartbeat builds and POSTs one heartbeat request. On a 2xx response,
// it applies next_heartbeat_seconds (clamped) to cfg.HeartbeatInterval for
// subsequent cycles, and returns the response's own pending_operations for
// the caller (runOneCycle) to report results for. A non-2xx response or
// network error is returned as an error, which the caller treats as a hard
// failure that triggers backoff and skips both the cron-execution report
// and operation-result reporting for this cycle.
func sendHeartbeat(client *http.Client, cfg *Config, credential string) ([]protocol.OperationEnvelope, error) {
	req := heartbeatRequest{
		ProtocolVersion: cfg.ProtocolVersion,
		NodeUUID:        cfg.NodeUUID,
		AgentVersion:    cfg.AgentVersion,
		UbuntuRelease:   ubuntuRelease(osReleasePath),
		Architecture:    runtime.GOARCH,
		Capabilities:    presentCapabilities(cfg.CapabilityStateRoots),
		Timestamp:       time.Now().UTC().Format(time.RFC3339),
	}

	body, err := json.Marshal(req)
	if err != nil {
		return nil, fmt.Errorf("marshaling heartbeat request: %w", err)
	}

	httpReq, err := http.NewRequest(http.MethodPost, cfg.ControlPlaneURL+"/agent/v1/heartbeat", bytes.NewReader(body))
	if err != nil {
		return nil, fmt.Errorf("building heartbeat request: %w", err)
	}

	httpReq.Header.Set("Content-Type", "application/json")
	httpReq.Header.Set("Authorization", "Bearer "+credential)

	resp, err := client.Do(httpReq)
	if err != nil {
		return nil, fmt.Errorf("sending heartbeat request: %w", err)
	}
	defer resp.Body.Close()

	respBody, _ := io.ReadAll(resp.Body)

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return nil, fmt.Errorf("heartbeat request returned status %d: %s", resp.StatusCode, string(respBody))
	}

	var parsed heartbeatResponse
	if err := json.Unmarshal(respBody, &parsed); err != nil {
		// A 2xx with an unparseable body is still a successful heartbeat;
		// simply keep the current interval rather than failing the cycle.
		return nil, nil
	}

	if parsed.NextHeartbeatSeconds >= minHeartbeatSeconds && parsed.NextHeartbeatSeconds <= maxHeartbeatSeconds {
		cfg.HeartbeatInterval = time.Duration(parsed.NextHeartbeatSeconds) * time.Second
	}

	return parsed.PendingOperations, nil
}

// presentCapabilities reports "healthy" for each capability whose fixed
// production StateRoot directory exists on this node, omitting any
// capability whose path does not exist entirely (never reporting a
// capability that isn't installed on this node at all).
func presentCapabilities(stateRoots map[string]string) []capabilityStatus {
	statuses := make([]capabilityStatus, 0, len(stateRoots))

	for capability, path := range stateRoots {
		if _, err := os.Stat(path); err != nil {
			continue
		}

		statuses = append(statuses, capabilityStatus{
			Capability:  capability,
			HealthState: "healthy",
		})
	}

	return statuses
}

// ubuntuRelease reads VERSION_ID out of /etc/os-release, e.g. "24.04".
// Returns "" if the file is missing or the field is absent, the safe
// default for a node this daemon cannot positively identify.
func ubuntuRelease(path string) string {
	raw, err := os.ReadFile(path)
	if err != nil {
		return ""
	}

	for _, line := range strings.Split(string(raw), "\n") {
		line = strings.TrimSpace(line)
		if !strings.HasPrefix(line, "VERSION_ID=") {
			continue
		}

		value := strings.TrimPrefix(line, "VERSION_ID=")

		return strings.Trim(value, `"`)
	}

	return ""
}
