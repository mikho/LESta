package daemon

import (
	"encoding/json"
	"net/http"
	"net/http/httptest"
	"os"
	"path/filepath"
	"testing"
)

func writeExecutionLog(t *testing.T, dir, resourceID string, entries ...executionLogEntry) string {
	t.Helper()

	execDir := filepath.Join(dir, "executions")
	if err := os.MkdirAll(execDir, 0o755); err != nil {
		t.Fatalf("MkdirAll: %v", err)
	}

	path := filepath.Join(execDir, resourceID+".log")

	f, err := os.OpenFile(path, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o640)
	if err != nil {
		t.Fatalf("OpenFile: %v", err)
	}
	defer f.Close()

	for _, entry := range entries {
		line, err := json.Marshal(entry)
		if err != nil {
			t.Fatalf("Marshal: %v", err)
		}

		if _, err := f.Write(append(line, '\n')); err != nil {
			t.Fatalf("Write: %v", err)
		}
	}

	return path
}

func TestReportCronExecutionsSendsNewEntriesAndAdvancesWatermark(t *testing.T) {
	dir := t.TempDir()
	writeExecutionLog(t, dir, "job-1", executionLogEntry{
		StartedAt: "2026-01-01T00:00:00Z", FinishedAt: "2026-01-01T00:00:01Z", ExitCode: 0, Output: "ok",
	})

	var receivedRequests int

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		receivedRequests++

		var req cronExecutionsRequest
		if err := json.NewDecoder(r.Body).Decode(&req); err != nil {
			t.Fatalf("decoding request: %v", err)
		}

		if len(req.Executions) != 1 {
			t.Fatalf("got %d executions, want 1", len(req.Executions))
		}

		if req.Executions[0].ResourceID != "job-1" {
			t.Errorf("ResourceID = %q, want job-1", req.Executions[0].ResourceID)
		}

		w.WriteHeader(http.StatusOK)
	}))
	defer server.Close()

	cfg := Config{
		ControlPlaneURL: server.URL,
		NodeUUID:        "node-1",
		CronStateRoot:   dir,
		WatermarkPath:   filepath.Join(dir, "watermark.json"),
	}

	if err := reportCronExecutions(server.Client(), cfg, "credential"); err != nil {
		t.Fatalf("reportCronExecutions returned error: %v", err)
	}

	if receivedRequests != 1 {
		t.Fatalf("server received %d requests, want 1", receivedRequests)
	}

	// A second call with no new entries must not POST at all.
	if err := reportCronExecutions(server.Client(), cfg, "credential"); err != nil {
		t.Fatalf("second reportCronExecutions returned error: %v", err)
	}

	if receivedRequests != 1 {
		t.Fatalf("server received %d requests after a no-op cycle, want still 1", receivedRequests)
	}
}

func TestReportCronExecutionsSkipsEmptyBatch(t *testing.T) {
	dir := t.TempDir()

	var called bool

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		called = true
	}))
	defer server.Close()

	cfg := Config{
		ControlPlaneURL: server.URL,
		CronStateRoot:   dir,
		WatermarkPath:   filepath.Join(dir, "watermark.json"),
	}

	if err := reportCronExecutions(server.Client(), cfg, "credential"); err != nil {
		t.Fatalf("reportCronExecutions returned error: %v", err)
	}

	if called {
		t.Error("server was called despite there being no execution logs at all")
	}
}

func TestReportCronExecutionsLeavesWatermarkUntouchedOnFailure(t *testing.T) {
	dir := t.TempDir()
	writeExecutionLog(t, dir, "job-1", executionLogEntry{
		StartedAt: "2026-01-01T00:00:00Z", FinishedAt: "2026-01-01T00:00:01Z", ExitCode: 0, Output: "ok",
	})

	server := httptest.NewServer(http.HandlerFunc(func(w http.ResponseWriter, r *http.Request) {
		w.WriteHeader(http.StatusInternalServerError)
	}))
	defer server.Close()

	watermarkPath := filepath.Join(dir, "watermark.json")
	cfg := Config{
		ControlPlaneURL: server.URL,
		CronStateRoot:   dir,
		WatermarkPath:   watermarkPath,
	}

	if err := reportCronExecutions(server.Client(), cfg, "credential"); err == nil {
		t.Fatal("expected an error from a 500 response, got nil")
	}

	if _, err := os.Stat(watermarkPath); !os.IsNotExist(err) {
		t.Errorf("watermark file was written despite the POST failing: err=%v", err)
	}
}

func TestReadNewEntriesHandlesTruncationReset(t *testing.T) {
	dir := t.TempDir()
	path := writeExecutionLog(t, dir, "job-1", executionLogEntry{
		StartedAt: "2026-01-01T00:00:00Z", FinishedAt: "2026-01-01T00:00:01Z", ExitCode: 0, Output: "first",
	})

	// Simulate having previously recorded a watermark near runner.go's own
	// 1 MiB logCapBytes cap, then the file being reset (truncated to empty)
	// and one small new entry appended, mirroring its reset behavior. A
	// large, cap-sized prior offset (rather than the tiny pre-reset file's
	// own real size) is what makes the "current size < recorded offset"
	// truncation heuristic actually fire, matching the real scale at which
	// runner.go resets a file.
	priorOffset := int64(1 << 20)

	if err := os.Truncate(path, 0); err != nil {
		t.Fatalf("Truncate: %v", err)
	}

	f, err := os.OpenFile(path, os.O_APPEND|os.O_WRONLY, 0o640)
	if err != nil {
		t.Fatalf("OpenFile: %v", err)
	}

	entry := executionLogEntry{StartedAt: "2026-01-02T00:00:00Z", FinishedAt: "2026-01-02T00:00:01Z", ExitCode: 1, Output: "second"}
	line, _ := json.Marshal(entry)

	if _, err := f.Write(append(line, '\n')); err != nil {
		t.Fatalf("Write: %v", err)
	}
	f.Close()

	entries, offset, err := readNewEntries(path, priorOffset, maxExecutionsPerCycle)
	if err != nil {
		t.Fatalf("readNewEntries returned error: %v", err)
	}

	if len(entries) != 1 {
		t.Fatalf("got %d entries, want 1 (the post-truncation entry)", len(entries))
	}

	if entries[0].Output != "second" {
		t.Errorf("Output = %q, want %q", entries[0].Output, "second")
	}

	newInfo, err := os.Stat(path)
	if err != nil {
		t.Fatalf("Stat: %v", err)
	}

	if offset != newInfo.Size() {
		t.Errorf("offset = %d, want %d (end of the reset file)", offset, newInfo.Size())
	}
}

func TestReadNewEntriesLeavesIncompleteTrailingLineUnparsed(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "job-1.log")

	complete := executionLogEntry{StartedAt: "2026-01-01T00:00:00Z", FinishedAt: "2026-01-01T00:00:01Z", ExitCode: 0, Output: "ok"}
	completeLine, _ := json.Marshal(complete)

	// A complete line followed by a partial, unterminated line, as if the
	// writer were interrupted mid-append.
	content := string(completeLine) + "\n" + `{"started_at":"2026-01-01T00:01:00Z"`

	if err := os.WriteFile(path, []byte(content), 0o640); err != nil {
		t.Fatalf("WriteFile: %v", err)
	}

	entries, offset, err := readNewEntries(path, 0, maxExecutionsPerCycle)
	if err != nil {
		t.Fatalf("readNewEntries returned error: %v", err)
	}

	if len(entries) != 1 {
		t.Fatalf("got %d entries, want 1 (the incomplete trailing line must not be parsed)", len(entries))
	}

	wantOffset := int64(len(completeLine) + 1)
	if offset != wantOffset {
		t.Errorf("offset = %d, want %d (position must not advance past the incomplete line)", offset, wantOffset)
	}
}

func TestReadNewEntriesRespectsLimit(t *testing.T) {
	dir := t.TempDir()
	path := writeExecutionLog(t, dir, "job-1",
		executionLogEntry{StartedAt: "1", FinishedAt: "1", ExitCode: 0, Output: "a"},
		executionLogEntry{StartedAt: "2", FinishedAt: "2", ExitCode: 0, Output: "b"},
		executionLogEntry{StartedAt: "3", FinishedAt: "3", ExitCode: 0, Output: "c"},
	)

	entries, offset, err := readNewEntries(path, 0, 2)
	if err != nil {
		t.Fatalf("readNewEntries returned error: %v", err)
	}

	if len(entries) != 2 {
		t.Fatalf("got %d entries, want 2 (limit enforced)", len(entries))
	}

	// A subsequent read from offset must pick up the third entry.
	rest, _, err := readNewEntries(path, offset, maxExecutionsPerCycle)
	if err != nil {
		t.Fatalf("readNewEntries (second call) returned error: %v", err)
	}

	if len(rest) != 1 || rest[0].Output != "c" {
		t.Fatalf("second call returned %+v, want exactly the third entry", rest)
	}
}

func TestWatermarkRoundTrip(t *testing.T) {
	dir := t.TempDir()
	path := filepath.Join(dir, "watermark.json")

	wm := watermarkFile{Offsets: map[string]int64{"job-1": 42}}

	if err := writeWatermark(path, wm); err != nil {
		t.Fatalf("writeWatermark: %v", err)
	}

	got, err := readWatermark(path)
	if err != nil {
		t.Fatalf("readWatermark: %v", err)
	}

	if got.Offsets["job-1"] != 42 {
		t.Errorf("Offsets[job-1] = %d, want 42", got.Offsets["job-1"])
	}
}

func TestReadWatermarkMissingFileReturnsEmptyMap(t *testing.T) {
	dir := t.TempDir()

	wm, err := readWatermark(filepath.Join(dir, "does-not-exist.json"))
	if err != nil {
		t.Fatalf("readWatermark returned error for a missing file: %v", err)
	}

	if wm.Offsets == nil || len(wm.Offsets) != 0 {
		t.Errorf("Offsets = %+v, want an empty, non-nil map", wm.Offsets)
	}
}
