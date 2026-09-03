package cron

import (
	"bytes"
	"encoding/json"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"path/filepath"
	"time"
)

const (
	// outputCapBytes bounds how much combined stdout+stderr a single
	// execution-log entry ever carries. Exceeding it truncates the
	// captured output and appends a note, never grows the entry unbounded.
	outputCapBytes = 65536

	// logCapBytes bounds one resource's own execution log file. Exceeding
	// it resets the file to empty before the next append: intentionally
	// simple ("reset when it gets too big"), not true rotation.
	logCapBytes = 1 << 20 // 1 MiB

	// startFailedExitCode is returned when the command could not even be
	// started (e.g. /bin/sh missing) -- distinct from any real exit code a
	// started process could itself return.
	startFailedExitCode = 127

	// sidecarUnreadableExitCode is returned when the job's own JSON
	// sidecar cannot be read or parsed at all, so no command was ever
	// attempted.
	sidecarUnreadableExitCode = 1
)

// executionLogEntry is one JSON-lines entry appended to
// StateRoot/executions/<resource_id>.log.
type executionLogEntry struct {
	StartedAt  string `json:"started_at"`
	FinishedAt string `json:"finished_at"`
	ExitCode   int    `json:"exit_code"`
	Output     string `json:"output"`
}

// RunJob is invoked by the `lesta-agent cron-run <resource_id>` CLI mode
// (wired in cmd/lesta-agent/main.go), never by the OperationEnvelope
// pipeline. It is the process cron itself execs on schedule, running as
// cfg.RunnerUser (whichever user actually owns the crontab line, enforced by
// /etc/cron.d itself, not by this code). It reads the job's real command
// from its JSON sidecar, execs it via `sh -c`, captures combined
// stdout+stderr bounded to outputCapBytes, and appends one capped
// execution-log entry. Returns the command's own exit code (or a synthetic
// non-zero code if the sidecar can't be read or the command can't even
// start), which main.go uses as this process's own exit code, matching
// normal cron semantics.
func RunJob(cfg Config, resourceID string) int {
	started := time.Now().UTC()

	sidecarPath := filepath.Join(cfg.StateRoot, "jobs", "sidecar", resourceID+".json")

	raw, err := os.ReadFile(sidecarPath)
	if err != nil {
		appendExecutionLog(cfg, resourceID, started, time.Now().UTC(), sidecarUnreadableExitCode,
			fmt.Sprintf("could not read sidecar %s: %v", sidecarPath, err))

		return sidecarUnreadableExitCode
	}

	var sidecar sidecarContent
	if err := json.Unmarshal(raw, &sidecar); err != nil {
		appendExecutionLog(cfg, resourceID, started, time.Now().UTC(), sidecarUnreadableExitCode,
			fmt.Sprintf("could not parse sidecar %s: %v", sidecarPath, err))

		return sidecarUnreadableExitCode
	}

	cmd := exec.Command("sh", "-c", sidecar.Command)

	var buf bytes.Buffer
	cmd.Stdout = &buf
	cmd.Stderr = &buf

	runErr := cmd.Run()
	finished := time.Now().UTC()

	exitCode := 0

	if runErr != nil {
		var exitErr *exec.ExitError
		if errors.As(runErr, &exitErr) {
			exitCode = exitErr.ExitCode()
		} else {
			exitCode = startFailedExitCode
		}
	}

	appendExecutionLog(cfg, resourceID, started, finished, exitCode, boundOutput(buf.String()))

	return exitCode
}

// boundOutput truncates output to outputCapBytes, appending a note when it
// does.
func boundOutput(output string) string {
	if len(output) <= outputCapBytes {
		return output
	}

	return output[:outputCapBytes] + "\n... [truncated]"
}

// appendExecutionLog appends one JSON-lines entry to
// StateRoot/executions/<resource_id>.log, creating the executions directory
// if missing and resetting the file first if it already exceeds logCapBytes.
// Best-effort: a failure here must never change the exit code RunJob
// returns, since the command itself already ran to completion (or failed to
// start) by the time this is called.
func appendExecutionLog(cfg Config, resourceID string, started, finished time.Time, exitCode int, output string) {
	dir := filepath.Join(cfg.StateRoot, "executions")
	if err := os.MkdirAll(dir, 0o755); err != nil {
		return
	}

	path := filepath.Join(dir, resourceID+".log")

	if info, err := os.Stat(path); err == nil && info.Size() > logCapBytes {
		_ = os.Truncate(path, 0)
	}

	entry := executionLogEntry{
		StartedAt:  started.Format(time.RFC3339),
		FinishedAt: finished.Format(time.RFC3339),
		ExitCode:   exitCode,
		Output:     output,
	}

	line, err := json.Marshal(entry)
	if err != nil {
		return
	}

	f, err := os.OpenFile(path, os.O_APPEND|os.O_CREATE|os.O_WRONLY, 0o640)
	if err != nil {
		return
	}
	defer f.Close()

	line = append(line, '\n')
	_, _ = f.Write(line)
}
