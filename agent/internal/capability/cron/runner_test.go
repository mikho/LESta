package cron_test

import (
	"bufio"
	"encoding/json"
	"os"
	"path/filepath"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/cron"
)

type testExecutionLogEntry struct {
	StartedAt  string `json:"started_at"`
	FinishedAt string `json:"finished_at"`
	ExitCode   int    `json:"exit_code"`
	Output     string `json:"output"`
}

func writeSidecar(t *testing.T, cfg cron.Config, resourceID, command string) {
	t.Helper()

	dir := filepath.Join(cfg.StateRoot, "jobs", "sidecar")
	if err := os.MkdirAll(dir, 0o750); err != nil {
		t.Fatalf("creating sidecar directory: %v", err)
	}

	raw, err := json.Marshal(map[string]string{"command": command})
	if err != nil {
		t.Fatalf("marshaling sidecar: %v", err)
	}

	if err := os.WriteFile(filepath.Join(dir, resourceID+".json"), raw, 0o640); err != nil {
		t.Fatalf("writing sidecar: %v", err)
	}
}

func readLastLogEntry(t *testing.T, cfg cron.Config, resourceID string) testExecutionLogEntry {
	t.Helper()

	path := filepath.Join(cfg.StateRoot, "executions", resourceID+".log")

	f, err := os.Open(path)
	if err != nil {
		t.Fatalf("opening execution log: %v", err)
	}
	defer f.Close()

	var last string

	scanner := bufio.NewScanner(f)
	scanner.Buffer(make([]byte, 0, 128*1024), 1024*1024)
	for scanner.Scan() {
		line := scanner.Text()
		if strings.TrimSpace(line) != "" {
			last = line
		}
	}
	if err := scanner.Err(); err != nil {
		t.Fatalf("scanning execution log: %v", err)
	}
	if last == "" {
		t.Fatalf("expected at least one execution-log entry in %s", path)
	}

	var entry testExecutionLogEntry
	if err := json.Unmarshal([]byte(last), &entry); err != nil {
		t.Fatalf("parsing execution-log entry %q: %v", last, err)
	}

	return entry
}

func TestRunJobSuccessfulCommandExitsZeroAndLogsOutput(t *testing.T) {
	cfg := cron.Config{
		FragmentDir:     filepath.Join(t.TempDir(), "cron.d"),
		StateRoot:       t.TempDir(),
		RunnerUser:      "lesta-cron",
		AgentBinaryPath: "/var/lib/lesta/agent/bin/lesta-agent",
	}
	resourceID := newTestUUID()

	writeSidecar(t, cfg, resourceID, "echo hello && exit 0")

	exitCode := cron.RunJob(cfg, resourceID)
	if exitCode != 0 {
		t.Fatalf("expected exit code 0, got %d", exitCode)
	}

	entry := readLastLogEntry(t, cfg, resourceID)
	if entry.ExitCode != 0 {
		t.Fatalf("expected logged exit code 0, got %d", entry.ExitCode)
	}
	if !strings.Contains(entry.Output, "hello") {
		t.Fatalf("expected logged output to contain %q, got %q", "hello", entry.Output)
	}
	if entry.StartedAt == "" || entry.FinishedAt == "" {
		t.Fatalf("expected non-empty started_at/finished_at, got %+v", entry)
	}
}

func TestRunJobFailingCommandReturnsItsRealExitCode(t *testing.T) {
	cfg := cron.Config{
		FragmentDir:     filepath.Join(t.TempDir(), "cron.d"),
		StateRoot:       t.TempDir(),
		RunnerUser:      "lesta-cron",
		AgentBinaryPath: "/var/lib/lesta/agent/bin/lesta-agent",
	}
	resourceID := newTestUUID()

	writeSidecar(t, cfg, resourceID, "exit 3")

	exitCode := cron.RunJob(cfg, resourceID)
	if exitCode != 3 {
		t.Fatalf("expected exit code 3, got %d", exitCode)
	}

	entry := readLastLogEntry(t, cfg, resourceID)
	if entry.ExitCode != 3 {
		t.Fatalf("expected logged exit code 3, got %d", entry.ExitCode)
	}
}

func TestRunJobTruncatesOutputExceedingTheCap(t *testing.T) {
	cfg := cron.Config{
		FragmentDir:     filepath.Join(t.TempDir(), "cron.d"),
		StateRoot:       t.TempDir(),
		RunnerUser:      "lesta-cron",
		AgentBinaryPath: "/var/lib/lesta/agent/bin/lesta-agent",
	}
	resourceID := newTestUUID()

	// Produces well over the 65536-byte cap.
	writeSidecar(t, cfg, resourceID, "head -c 200000 /dev/zero | tr '\\0' 'a'")

	exitCode := cron.RunJob(cfg, resourceID)
	if exitCode != 0 {
		t.Fatalf("expected exit code 0, got %d", exitCode)
	}

	entry := readLastLogEntry(t, cfg, resourceID)
	if !strings.HasSuffix(entry.Output, "[truncated]") {
		t.Fatalf("expected the logged output to end with a truncation note, got a %d-byte tail: %q", len(entry.Output), entry.Output[max(0, len(entry.Output)-40):])
	}
	if len(entry.Output) > 65536+len("\n... [truncated]") {
		t.Fatalf("expected the logged output to stay bounded near the 65536-byte cap, got %d bytes", len(entry.Output))
	}
}

func TestRunJobUnreadableSidecarReturnsASyntheticNonZeroExitCode(t *testing.T) {
	cfg := cron.Config{
		FragmentDir:     filepath.Join(t.TempDir(), "cron.d"),
		StateRoot:       t.TempDir(),
		RunnerUser:      "lesta-cron",
		AgentBinaryPath: "/var/lib/lesta/agent/bin/lesta-agent",
	}

	exitCode := cron.RunJob(cfg, newTestUUID())
	if exitCode == 0 {
		t.Fatal("expected a non-zero exit code when the sidecar cannot be read")
	}
}
