package daemon

import (
	"bufio"
	"bytes"
	"encoding/json"
	"fmt"
	"io"
	"net/http"
	"os"
	"path/filepath"
	"sort"
	"strings"
)

// maxExecutionsPerCycle caps the total number of execution-log entries read
// and reported across all resource log files in a single cycle. Any
// leftover is naturally retried next cycle, since the watermark for a file
// hit by the cap is only advanced past the lines actually parsed.
const maxExecutionsPerCycle = 500

// watermarkFile is the on-disk record of, per resource_id, the byte offset
// already successfully reported to the control plane.
type watermarkFile struct {
	Offsets map[string]int64 `json:"offsets"`
}

// executionLogEntry mirrors internal/capability/cron/runner.go's own
// executionLogEntry JSON-lines shape exactly.
type executionLogEntry struct {
	StartedAt  string `json:"started_at"`
	FinishedAt string `json:"finished_at"`
	ExitCode   int    `json:"exit_code"`
	Output     string `json:"output"`
}

// reportedExecution is one entry in the batch POSTed to the control plane,
// the log entry plus the resource_id its own filename carried.
type reportedExecution struct {
	ResourceID string `json:"resource_id"`
	StartedAt  string `json:"started_at"`
	FinishedAt string `json:"finished_at"`
	ExitCode   int    `json:"exit_code"`
	Output     string `json:"output"`
}

type cronExecutionsRequest struct {
	NodeUUID   string              `json:"node_uuid"`
	Executions []reportedExecution `json:"executions"`
}

// reportCronExecutions scans CronStateRoot/executions/*.log for entries
// beyond each file's own recorded watermark offset, POSTs any new entries
// (capped at maxExecutionsPerCycle total) to the control plane, and, only
// on a 2xx response, advances the watermark to the position actually
// reached. On any failure the watermark file is left untouched, so the
// same entries are naturally resent next cycle.
func reportCronExecutions(client *http.Client, cfg Config, credential string) error {
	watermark, err := readWatermark(cfg.WatermarkPath)
	if err != nil {
		return fmt.Errorf("reading watermark file: %w", err)
	}

	pattern := filepath.Join(cfg.CronStateRoot, "executions", "*.log")

	matches, err := filepath.Glob(pattern)
	if err != nil {
		return fmt.Errorf("globbing execution logs: %w", err)
	}

	// Deterministic ordering, so a repeated run with the same set of files
	// behaves identically and tests are stable.
	sort.Strings(matches)

	var batch []reportedExecution

	newOffsets := make(map[string]int64, len(matches))

	for _, path := range matches {
		if len(batch) >= maxExecutionsPerCycle {
			break
		}

		resourceID := strings.TrimSuffix(filepath.Base(path), ".log")

		entries, offset, err := readNewEntries(path, watermark.Offsets[resourceID], maxExecutionsPerCycle-len(batch))
		if err != nil {
			return fmt.Errorf("reading execution log %s: %w", path, err)
		}

		for _, entry := range entries {
			batch = append(batch, reportedExecution{
				ResourceID: resourceID,
				StartedAt:  entry.StartedAt,
				FinishedAt: entry.FinishedAt,
				ExitCode:   entry.ExitCode,
				Output:     entry.Output,
			})
		}

		newOffsets[resourceID] = offset
	}

	if len(batch) == 0 {
		return nil
	}

	if err := postCronExecutions(client, cfg, credential, batch); err != nil {
		return err
	}

	for resourceID, offset := range newOffsets {
		watermark.Offsets[resourceID] = offset
	}

	return writeWatermark(cfg.WatermarkPath, watermark)
}

// readNewEntries reads complete JSON-lines entries from path starting at
// offset, stopping after at most limit entries. If path's current size is
// smaller than offset, this is a post-truncation reset (runner.go's own
// 1 MiB reset behavior), so reading restarts from 0. An incomplete trailing
// line (the file was read mid-write) is left unparsed and the returned
// offset does not advance past it. Returns the entries read and the byte
// offset actually reached (the position immediately after the last fully
// parsed line).
func readNewEntries(path string, offset int64, limit int) ([]executionLogEntry, int64, error) {
	info, err := os.Stat(path)
	if err != nil {
		return nil, offset, err
	}

	if info.Size() < offset {
		offset = 0
	}

	f, err := os.Open(path)
	if err != nil {
		return nil, offset, err
	}
	defer f.Close()

	if _, err := f.Seek(offset, io.SeekStart); err != nil {
		return nil, offset, err
	}

	reader := bufio.NewReader(f)

	var entries []executionLogEntry

	position := offset

	for len(entries) < limit {
		line, err := reader.ReadString('\n')
		if err != nil {
			// Either EOF or a real read error; either way, an incomplete
			// trailing line (no terminating newline) is never parsed, and
			// position is left at the last confirmed line boundary.
			break
		}

		var entry executionLogEntry
		if unmarshalErr := json.Unmarshal([]byte(strings.TrimRight(line, "\n")), &entry); unmarshalErr != nil {
			// A malformed line is skipped but still advances position, so a
			// single corrupt entry can never permanently wedge this file.
			position += int64(len(line))

			continue
		}

		entries = append(entries, entry)
		position += int64(len(line))
	}

	return entries, position, nil
}

// postCronExecutions POSTs one batch to the control plane. A non-2xx
// response or network error is returned as an error.
func postCronExecutions(client *http.Client, cfg Config, credential string, batch []reportedExecution) error {
	body, err := json.Marshal(cronExecutionsRequest{
		NodeUUID:   cfg.NodeUUID,
		Executions: batch,
	})
	if err != nil {
		return fmt.Errorf("marshaling cron-executions request: %w", err)
	}

	httpReq, err := http.NewRequest(http.MethodPost, cfg.ControlPlaneURL+"/agent/v1/cron-executions", bytes.NewReader(body))
	if err != nil {
		return fmt.Errorf("building cron-executions request: %w", err)
	}

	httpReq.Header.Set("Content-Type", "application/json")
	httpReq.Header.Set("Authorization", "Bearer "+credential)

	resp, err := client.Do(httpReq)
	if err != nil {
		return fmt.Errorf("sending cron-executions request: %w", err)
	}
	defer resp.Body.Close()

	respBody, _ := io.ReadAll(resp.Body)

	if resp.StatusCode < 200 || resp.StatusCode >= 300 {
		return fmt.Errorf("cron-executions request returned status %d: %s", resp.StatusCode, string(respBody))
	}

	return nil
}

// readWatermark reads the watermark file, tolerating a missing file by
// returning an empty map.
func readWatermark(path string) (watermarkFile, error) {
	raw, err := os.ReadFile(path)
	if err != nil {
		if os.IsNotExist(err) {
			return watermarkFile{Offsets: map[string]int64{}}, nil
		}

		return watermarkFile{}, err
	}

	var wm watermarkFile
	if err := json.Unmarshal(raw, &wm); err != nil {
		return watermarkFile{}, err
	}

	if wm.Offsets == nil {
		wm.Offsets = map[string]int64{}
	}

	return wm, nil
}

// writeWatermark writes the watermark file atomically: write to a .tmp
// sibling, then rename over the real path.
func writeWatermark(path string, wm watermarkFile) error {
	raw, err := json.Marshal(wm)
	if err != nil {
		return fmt.Errorf("marshaling watermark file: %w", err)
	}

	dir := filepath.Dir(path)
	if err := os.MkdirAll(dir, 0o750); err != nil {
		return fmt.Errorf("creating watermark directory: %w", err)
	}

	tmpPath := path + ".tmp"

	if err := os.WriteFile(tmpPath, raw, 0o640); err != nil {
		return fmt.Errorf("writing watermark tmp file: %w", err)
	}

	if err := os.Rename(tmpPath, path); err != nil {
		return fmt.Errorf("renaming watermark tmp file into place: %w", err)
	}

	return nil
}
