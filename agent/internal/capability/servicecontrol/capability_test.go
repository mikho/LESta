package servicecontrol

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// fakeSystemctl records every call and answers "active" to show; it fails when asked to start a
// unit named "broken".
func fakeSystemctl(t *testing.T) (string, string) {
	t.Helper()

	dir := t.TempDir()
	log := filepath.Join(dir, "calls")
	path := filepath.Join(dir, "systemctl")
	script := `#!/bin/sh
echo "$@" >> ` + log + `
case "$1" in
show) echo active ;;
*) [ "$3" = broken ] && { echo "Job failed" >&2; exit 1; } ;;
esac
exit 0
`
	if err := os.WriteFile(path, []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}

	return path, log
}

func op(key, unit, action string) protocol.OperationEnvelope {
	payload, _ := json.Marshal(map[string]string{"unit": unit, "action": action})

	return protocol.OperationEnvelope{
		ProtocolVersion:     "1",
		Capability:          "node.service-control.v1",
		Operation:           protocol.OperationUpdate,
		ResourceID:          "00000000-0000-4000-8000-000000000001",
		DesiredStateVersion: 1,
		IdempotencyKey:      key,
		CorrelationID:       "00000000-0000-4000-8000-000000000002",
		Payload:             payload,
	}
}

func TestAnAllowedUnitIsControlledAndItsStateReported(t *testing.T) {
	systemctl, log := fakeSystemctl(t)
	c := New(Config{SystemctlBinary: systemctl, AllowedUnits: DefaultAllowedUnits})

	res, err := c.Apply(context.Background(), op("k1", "nginx", "stop"))
	if err != nil || res.Status != protocol.StatusApplied {
		t.Fatalf("status %s, err %v, errors %+v", res.Status, err, res.Errors)
	}

	if !strings.Contains(string(res.Data), `"unit":"nginx"`) || !strings.Contains(string(res.Data), `"state":"active"`) {
		t.Errorf("data = %s", res.Data)
	}

	calls, _ := os.ReadFile(log)
	if !strings.HasPrefix(string(calls), "stop -- nginx\n") {
		t.Errorf("calls = %q", calls)
	}

	again, _ := c.Apply(context.Background(), op("k1", "nginx", "stop"))
	if again.Status != protocol.StatusAlreadyApplied {
		t.Errorf("a replay must not stop the unit again, got %s", again.Status)
	}
}

func TestUnitsOffTheListAndUnknownActionsAreRefusedWithoutTouchingSystemd(t *testing.T) {
	systemctl, log := fakeSystemctl(t)
	c := New(Config{SystemctlBinary: systemctl, AllowedUnits: DefaultAllowedUnits})

	for i, tc := range []struct{ unit, action, code string }{
		{"ssh", "stop", "unit_not_allowed"},
		{"lesta-applier", "stop", "unit_not_allowed"},
		{"lesta-agent-daemon", "restart", "unit_not_allowed"},
		{"nginx; reboot", "stop", "unit_not_allowed"},
		{"nginx", "mask", "invalid_action"},
		{"nginx", "disable", "invalid_action"},
	} {
		res, _ := c.Apply(context.Background(), op(string(rune('a'+i)), tc.unit, tc.action))
		if res.Status != protocol.StatusRejected || len(res.Errors) != 1 || res.Errors[0].Code != tc.code {
			t.Errorf("%s %s: status %s errors %+v, want rejected %s", tc.action, tc.unit, res.Status, res.Errors, tc.code)
		}
	}

	if calls, _ := os.ReadFile(log); len(calls) != 0 {
		t.Errorf("systemctl must not have been called: %q", calls)
	}
}

func TestAFailingSystemctlIsReportedAsFailed(t *testing.T) {
	systemctl, _ := fakeSystemctl(t)
	c := New(Config{SystemctlBinary: systemctl, AllowedUnits: []string{"broken"}})

	res, _ := c.Apply(context.Background(), op("k", "broken", "start"))
	if res.Status != protocol.StatusFailed || res.Errors[0].Code != "systemctl_failed" || !strings.Contains(res.Errors[0].Message, "Job failed") {
		t.Errorf("status %s errors %+v", res.Status, res.Errors)
	}
}
