package metrics

import (
	"bufio"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"regexp"
)

// combinedLogPattern matches the standard "combined" access-log format both
// nginx's own built-in combined format and this project's own explicit
// apache CustomLog "..." combined directive produce:
//
//	$remote_addr - $remote_user [$time_local] "$request" $status $bytes_sent "$referer" "$user_agent"
//
// Only $status and $bytes_sent are captured: this capability measures
// bandwidth and request counts, nothing about individual requests'
// destinations or contents. $bytes_sent is "-" when apache/nginx recorded no
// body (never a parse error), treated as 0.
var combinedLogPattern = regexp.MustCompile(`^\S+\s+\S+\s+\S+\s+\[[^\]]*\]\s+"[^"]*"\s+(\d+)\s+(\d+|-)`)

// webUsage is one WebResourceRef's own measured delta since its last
// collection.
type webUsage struct {
	RequestCount int64
	BytesSent    int64
}

// offsetState is this capability's own durable, per-resource bookkeeping:
// the last byte offset already read from that resource's own access log.
// Persisted because each observe call is its own fresh daemon-dispatched
// process invocation (see internal/idempotency's own doc comment on why
// in-process state never survives across calls here) -- without a durable
// offset, every collection would either double-count already-reported
// traffic (re-reading from byte 0 every time) or need the log truncated out
// from under it (see this package's own doc comment on why logrotate's
// copytruncate, not this capability, owns that).
type offsetState struct {
	Offset int64 `json:"offset"`
}

func offsetStatePath(offsetStateRoot, resourceUUID string) string {
	return filepath.Join(offsetStateRoot, resourceUUID+".json")
}

func readOffset(offsetStateRoot, resourceUUID string) (int64, error) {
	raw, err := os.ReadFile(offsetStatePath(offsetStateRoot, resourceUUID))
	if err != nil {
		if os.IsNotExist(err) {
			return 0, nil
		}

		return 0, err
	}

	var state offsetState
	if err := json.Unmarshal(raw, &state); err != nil {
		// A corrupt offset file is treated the same as "never collected
		// before" (re-read from the start) rather than a hard failure: the
		// worst real consequence is one collection cycle double-counting
		// traffic already reported once, which is far preferable to this
		// capability refusing to report any usage at all for a resource
		// with a damaged bookkeeping file.
		return 0, nil
	}

	return state.Offset, nil
}

func writeOffset(offsetStateRoot, resourceUUID string, offset int64) error {
	if err := os.MkdirAll(offsetStateRoot, 0o750); err != nil {
		return fmt.Errorf("creating offset state root: %w", err)
	}

	raw, err := json.Marshal(offsetState{Offset: offset})
	if err != nil {
		return fmt.Errorf("encoding offset state: %w", err)
	}

	path := offsetStatePath(offsetStateRoot, resourceUUID)

	if err := os.WriteFile(path+".tmp", raw, 0o640); err != nil {
		return fmt.Errorf("writing offset state: %w", err)
	}

	if err := os.Rename(path+".tmp", path); err != nil {
		return fmt.Errorf("activating offset state: %w", err)
	}

	return nil
}

// collectWebUsage reads ref's own access log from its last recorded offset
// (0 if never collected, or if the file is now SHORTER than that offset --
// logrotate's own copytruncate ran since the last collection, so this
// starts over from the beginning of the fresh, truncated file rather than
// seeking past its own end), parses every new line, and durably records the
// new end-of-file offset before returning. A missing log file (the resource
// was created but has never received a single request yet) reports zero
// usage, not an error.
func collectWebUsage(cfg Config, ref WebResourceRef) (webUsage, error) {
	logPath := filepath.Join(cfg.logDirFor(ref.WebServer), ref.ResourceUUID+".access.log")

	f, err := os.Open(logPath)
	if err != nil {
		if os.IsNotExist(err) {
			return webUsage{}, nil
		}

		return webUsage{}, fmt.Errorf("opening %s: %w", logPath, err)
	}
	defer f.Close()

	info, err := f.Stat()
	if err != nil {
		return webUsage{}, fmt.Errorf("stat %s: %w", logPath, err)
	}

	offset, err := readOffset(cfg.OffsetStateRoot, ref.ResourceUUID)
	if err != nil {
		return webUsage{}, fmt.Errorf("reading prior offset for %s: %w", ref.ResourceUUID, err)
	}

	if offset > info.Size() {
		offset = 0
	}

	if _, err := f.Seek(offset, 0); err != nil {
		return webUsage{}, fmt.Errorf("seeking %s to offset %d: %w", logPath, offset, err)
	}

	var usage webUsage

	scanner := bufio.NewScanner(f)
	scanner.Buffer(make([]byte, 0, 64*1024), 1024*1024)

	for scanner.Scan() {
		requests, bytesSent, ok := parseCombinedLogLine(scanner.Text())
		if !ok {
			// A malformed or foreign line (e.g. a stray blank line at EOF)
			// is skipped, never a hard failure: this capability's job is to
			// report real usage from whatever real, well-formed lines exist,
			// not to be a strict log-format validator.
			continue
		}

		usage.RequestCount += requests
		usage.BytesSent += bytesSent
	}

	if err := scanner.Err(); err != nil {
		return webUsage{}, fmt.Errorf("reading %s: %w", logPath, err)
	}

	if err := writeOffset(cfg.OffsetStateRoot, ref.ResourceUUID, info.Size()); err != nil {
		return webUsage{}, fmt.Errorf("recording new offset for %s: %w", ref.ResourceUUID, err)
	}

	return usage, nil
}

// parseCombinedLogLine returns (1, bytesSent, true) for a real combined-
// format access-log line, or (0, 0, false) for anything else.
func parseCombinedLogLine(line string) (int64, int64, bool) {
	m := combinedLogPattern.FindStringSubmatch(line)
	if m == nil {
		return 0, 0, false
	}

	if m[2] == "-" {
		return 1, 0, true
	}

	var bytesSent int64
	if _, err := fmt.Sscanf(m[2], "%d", &bytesSent); err != nil {
		return 1, 0, true
	}

	return 1, bytesSent, true
}
