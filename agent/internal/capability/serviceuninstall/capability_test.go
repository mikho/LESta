package serviceuninstall

import (
	"context"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/protocol"
)

// fakeInstaller records its arguments and prints a result line the way install-selected.sh does.
func fakeInstaller(t *testing.T, result string) (string, string) {
	t.Helper()

	dir := t.TempDir()
	log := filepath.Join(dir, "args")
	path := filepath.Join(dir, "install-selected.sh")
	script := "echo \"$@\" > " + log + "\necho 'some log line'\necho '" + result + "'\n"

	if err := os.WriteFile(path, []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}

	return path, log
}

func op(key string, payload map[string]any) protocol.OperationEnvelope {
	raw, _ := json.Marshal(payload)

	return protocol.OperationEnvelope{
		ProtocolVersion: "1", Capability: "node.service-uninstall.v1", Operation: protocol.OperationDelete,
		ResourceID: "00000000-0000-4000-8000-000000000001", DesiredStateVersion: 1, IdempotencyKey: key,
		CorrelationID: "00000000-0000-4000-8000-000000000002", Payload: raw,
	}
}

func TestARemovalRunsTheInstallerInRemoveMode(t *testing.T) {
	installer, log := fakeInstaller(t, `{"status":"applied","changes":[{"action":"removed_service","path":"cron","detail":"x"}],"errors":[]}`)
	c := New(Config{Installer: installer, TrustInstaller: trustAll})

	res, _ := c.Apply(context.Background(), op("k", map[string]any{"service": "cron"}))
	if res.Status != protocol.StatusApplied || !strings.Contains(string(res.Data), "removed_service") {
		t.Fatalf("status %s errors %+v data %s", res.Status, res.Errors, res.Data)
	}

	args, _ := os.ReadFile(log)
	if strings.TrimSpace(string(args)) != "--apply --yes --remove cron" {
		t.Errorf("args = %q", args)
	}

	if again, _ := c.Apply(context.Background(), op("k", map[string]any{"service": "cron"})); again.Status != protocol.StatusAlreadyApplied {
		t.Errorf("a replay must not run the installer again, got %s", again.Status)
	}
}

func TestDataLossIsOnlyConfirmedWhenAsked(t *testing.T) {
	installer, log := fakeInstaller(t, `{"status":"applied","changes":[],"errors":[]}`)
	c := New(Config{Installer: installer, TrustInstaller: trustAll})

	c.Apply(context.Background(), op("k", map[string]any{"service": "mail", "confirm_data_loss": true}))

	args, _ := os.ReadFile(log)
	if strings.TrimSpace(string(args)) != "--apply --yes --remove mail --confirm-data-loss mail" {
		t.Errorf("args = %q", args)
	}
}

func TestTheInstallersRefusalIsReported(t *testing.T) {
	installer, _ := fakeInstaller(t, `{"status":"failed","changes":[],"errors":[{"code":"still_required","message":"nginx is needed by mail on this node; remove that first"}]}`)
	c := New(Config{Installer: installer, TrustInstaller: trustAll})

	res, _ := c.Apply(context.Background(), op("k", map[string]any{"service": "nginx"}))
	if res.Status != protocol.StatusFailed || res.Errors[0].Code != "still_required" || !strings.Contains(res.Errors[0].Message, "needed by mail") {
		t.Errorf("status %s errors %+v", res.Status, res.Errors)
	}
}

func TestOnlyListedServicesAndDeleteAreAccepted(t *testing.T) {
	installer, log := fakeInstaller(t, `{"status":"applied","changes":[],"errors":[]}`)
	c := New(Config{Installer: installer, TrustInstaller: trustAll})

	for i, p := range []map[string]any{{"service": "agent-daemon"}, {"service": "firewall"}, {"service": "nginx --prune"}, {"service": "cron", "extra": 1}} {
		res, _ := c.Apply(context.Background(), op(string(rune('a'+i)), p))
		if res.Status != protocol.StatusRejected {
			t.Errorf("%v: status %s, want rejected", p, res.Status)
		}
	}

	update := op("u", map[string]any{"service": "cron"})
	update.Operation = protocol.OperationUpdate
	if res, _ := c.Apply(context.Background(), update); res.Status != protocol.StatusRejected {
		t.Errorf("update must be rejected, got %s", res.Status)
	}

	if _, err := os.Stat(log); err == nil {
		t.Errorf("the installer must not have run")
	}
}

func trustAll(string) error { return nil }

func TestAnInstallerOthersCanWriteIsNotRun(t *testing.T) {
	installer, log := fakeInstaller(t, `{"status":"applied","changes":[],"errors":[]}`)

	// The real check: a test's temporary directory sits under a world-writable /tmp.
	res, _ := New(Config{Installer: installer}).Apply(context.Background(), op("k", map[string]any{"service": "cron"}))
	if res.Status != protocol.StatusFailed || res.Errors[0].Code != "installer_untrusted" {
		t.Errorf("status %s errors %+v", res.Status, res.Errors)
	}

	if _, err := os.Stat(log); err == nil {
		t.Errorf("the installer must not have run")
	}
}

func TestRootOwnedAcceptsARootOnlyPathAndRefusesAWritableOne(t *testing.T) {
	if os.Geteuid() != 0 {
		t.Skip("needs root to create a root-owned directory")
	}

	dir, err := os.MkdirTemp("/root", "lesta-uninstall-test-")
	if err != nil {
		t.Skip("no root-owned place to test in: " + err.Error())
	}
	t.Cleanup(func() { _ = os.RemoveAll(dir) })

	if err := os.Chmod(dir, 0o755); err != nil {
		t.Fatal(err)
	}

	script := filepath.Join(dir, "install-selected.sh")
	if err := os.WriteFile(script, []byte("true\n"), 0o755); err != nil {
		t.Fatal(err)
	}

	if err := rootOwned(script); err != nil {
		t.Errorf("a root-only path must be trusted: %v", err)
	}

	if err := os.Chmod(script, 0o775); err != nil {
		t.Fatal(err)
	}

	if err := rootOwned(script); err == nil {
		t.Errorf("a group-writable installer must be refused")
	}
}
