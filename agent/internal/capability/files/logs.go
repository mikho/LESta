package files

import (
	"bufio"
	"bytes"
	"encoding/base64"
	"encoding/json"
	"errors"
	"fmt"
	"io"
	"net/url"
	"os"
	"path/filepath"
	"regexp"
	"sort"
	"strings"
	"time"
)

// This file serves a domain's own web logs over the same fast lane as the file
// manager: a bounded tail of the access or error log, a traffic summary built
// from the recent access log, and a raw download of the log's last megabytes.
// The log path is built only from the operation's resource id (a UUID the
// control plane authorised the caller for) and a fixed kind, never from a
// tenant-supplied path.

const (
	// maxTailLines bounds a tail request; maxLineBytes truncates a single line
	// so one hostile request cannot bloat the response.
	maxTailLines = 500
	maxLineBytes = 2000
	// maxTailReadBytes is how far back a tail reads to find its lines.
	maxTailReadBytes = 1 << 20
	// maxSummaryBytes is how much of the log's end the summary parses, and
	// maxDownloadBytes how much of it a raw download returns, matching the file
	// manager's own 4 MB ceiling.
	maxSummaryBytes  = 4 << 20
	maxDownloadBytes = 4 << 20
	// maxDistinctVisitors caps the summary's visitor set.
	maxDistinctVisitors = 100000
	topN                = 10
)

// combinedLine matches nginx's and apache's combined log format:
// $remote_addr - $remote_user [$time_local] "$request" $status $bytes "$referer" "$user_agent".
var combinedLine = regexp.MustCompile(`^(\S+) \S+ \S+ \[([^\]]+)\] "([^"]*)" (\d{3}) (\d+|-) "([^"]*)" "([^"]*)"`)

// observeLog answers a log observe operation. ok is false when the log cannot
// be served, with a code and message for the rejection.
func observeLog(cfg Config, resourceID string, p Payload) (data json.RawMessage, code, message string) {
	if !resourceIDPattern.MatchString(resourceID) {
		return nil, "invalid_resource_id", "the resource id is not a valid UUID"
	}

	path, err := cfg.logPath(resourceID, p.LogKind)
	if err != nil {
		return nil, "log_not_found", "no " + p.LogKind + " log exists for this domain yet"
	}

	switch p.LogMode {
	case "tail":
		lines, truncated, err := tailLines(path, clampLines(p.LogLines))
		if err != nil {
			return nil, "log_unreadable", err.Error()
		}

		return mustJSON(map[string]any{"kind": p.LogKind, "lines": lines, "truncated": truncated}), "", ""
	case "summary":
		if p.LogKind != "access" {
			return nil, "invalid_log_mode", "a summary is only available for the access log"
		}

		summary, err := summarizeAccessLog(path)
		if err != nil {
			return nil, "log_unreadable", err.Error()
		}

		return mustJSON(summary), "", ""
	case "download":
		content, truncated, err := tailBytes(path, maxDownloadBytes)
		if err != nil {
			return nil, "log_unreadable", err.Error()
		}

		return mustJSON(map[string]any{
			"kind":           p.LogKind,
			"filename":       p.LogKind + ".log",
			"truncated":      truncated,
			"content_base64": base64.StdEncoding.EncodeToString(content),
		}), "", ""
	}

	return nil, "invalid_log_mode", "log_mode must be tail, summary or download"
}

func clampLines(n int) int {
	if n <= 0 || n > maxTailLines {
		return 100
	}

	return n
}

func mustJSON(v any) json.RawMessage {
	b, err := json.Marshal(v)
	if err != nil {
		return json.RawMessage(`{}`)
	}

	return b
}

// logPath finds the domain's log in the first configured directory that has it.
func (c Config) logPath(resourceID, kind string) (string, error) {
	for _, dir := range c.LogDirs {
		candidate := filepath.Join(dir, resourceID+"."+kind+".log")
		if info, err := os.Stat(candidate); err == nil && info.Mode().IsRegular() {
			return candidate, nil
		}
	}

	return "", os.ErrNotExist
}

// tailBytes returns the last up to limit bytes of the file, starting at a line
// boundary, and whether anything earlier was left out.
func tailBytes(path string, limit int64) ([]byte, bool, error) {
	f, err := os.Open(path)
	if err != nil {
		return nil, false, fmt.Errorf("opening the log: %w", err)
	}
	defer func() { _ = f.Close() }()

	info, err := f.Stat()
	if err != nil {
		return nil, false, fmt.Errorf("reading the log size: %w", err)
	}

	start := int64(0)
	if info.Size() > limit {
		start = info.Size() - limit
	}

	buf := make([]byte, info.Size()-start)
	if _, err := f.ReadAt(buf, start); err != nil && !errors.Is(err, io.EOF) {
		return nil, false, fmt.Errorf("reading the log: %w", err)
	}

	if start > 0 {
		if i := bytes.IndexByte(buf, '\n'); i >= 0 {
			buf = buf[i+1:]
		}
	}

	return buf, start > 0, nil
}

func tailLines(path string, n int) ([]string, bool, error) {
	content, cut, err := tailBytes(path, maxTailReadBytes)
	if err != nil {
		return nil, false, err
	}

	all := strings.Split(strings.TrimRight(string(content), "\n"), "\n")
	if len(all) == 1 && all[0] == "" {
		return []string{}, false, nil
	}

	truncated := cut
	if len(all) > n {
		all = all[len(all)-n:]
		truncated = true
	}

	for i, line := range all {
		if len(line) > maxLineBytes {
			all[i] = line[:maxLineBytes] + "…"
		}
	}

	return all, truncated, nil
}

type countedItem struct {
	Name  string `json:"name"`
	Count int    `json:"count"`
}

type trafficSummary struct {
	Requests      int            `json:"requests"`
	BytesSent     int64          `json:"bytes_sent"`
	Visitors      int            `json:"visitors"`
	Status        map[string]int `json:"status"`
	TopPages      []countedItem  `json:"top_pages"`
	TopNotFound   []countedItem  `json:"top_not_found"`
	TopReferrers  []countedItem  `json:"top_referrers"`
	Browsers      []countedItem  `json:"browsers"`
	ByHour        [24]int        `json:"by_hour"`
	From          string         `json:"from"`
	To            string         `json:"to"`
	WindowPartial bool           `json:"window_partial"`
}

// summarizeAccessLog parses the end of the access log into a bounded summary.
func summarizeAccessLog(path string) (trafficSummary, error) {
	content, cut, err := tailBytes(path, maxSummaryBytes)
	if err != nil {
		return trafficSummary{}, err
	}

	summary := trafficSummary{Status: map[string]int{"2xx": 0, "3xx": 0, "4xx": 0, "5xx": 0, "other": 0}, WindowPartial: cut}
	pages := map[string]int{}
	notFound := map[string]int{}
	referrers := map[string]int{}
	browsers := map[string]int{}
	visitors := map[string]struct{}{}

	var first, last time.Time

	scanner := bufio.NewScanner(bytes.NewReader(content))
	scanner.Buffer(make([]byte, 64*1024), 1<<20)

	for scanner.Scan() {
		m := combinedLine.FindStringSubmatch(scanner.Text())
		if m == nil {
			continue
		}

		summary.Requests++

		if bytesSent := parseInt(m[5]); bytesSent > 0 {
			summary.BytesSent += bytesSent
		}

		if len(visitors) < maxDistinctVisitors {
			visitors[m[1]] = struct{}{}
		}

		switch m[4][0] {
		case '2':
			summary.Status["2xx"]++
		case '3':
			summary.Status["3xx"]++
		case '4':
			summary.Status["4xx"]++
		case '5':
			summary.Status["5xx"]++
		default:
			summary.Status["other"]++
		}

		if at, err := time.Parse("02/Jan/2006:15:04:05 -0700", m[2]); err == nil {
			summary.ByHour[at.Hour()]++

			if first.IsZero() || at.Before(first) {
				first = at
			}

			if at.After(last) {
				last = at
			}
		}

		page := requestPath(m[3])
		if page != "" && m[4][0] == '2' {
			pages[page]++
		}

		if page != "" && m[4] == "404" {
			notFound[page]++
		}

		if host := referrerHost(m[6]); host != "" {
			referrers[host]++
		}

		browsers[browserFamily(m[7])]++
	}

	summary.Visitors = len(visitors)
	summary.TopPages = topCounts(pages)
	summary.TopNotFound = topCounts(notFound)
	summary.TopReferrers = topCounts(referrers)
	summary.Browsers = topCounts(browsers)

	if !first.IsZero() {
		summary.From = first.UTC().Format(time.RFC3339)
		summary.To = last.UTC().Format(time.RFC3339)
	}

	return summary, nil
}

func parseInt(s string) int64 {
	var n int64

	for _, r := range s {
		if r < '0' || r > '9' {
			return 0
		}

		n = n*10 + int64(r-'0')
	}

	return n
}

// requestPath returns the path of `"GET /a/b?x=1 HTTP/1.1"` without its query.
func requestPath(request string) string {
	fields := strings.Fields(request)
	if len(fields) < 2 {
		return ""
	}

	path := fields[1]
	if i := strings.IndexByte(path, '?'); i >= 0 {
		path = path[:i]
	}

	if len(path) > 200 {
		path = path[:200]
	}

	return path
}

func referrerHost(referrer string) string {
	if referrer == "" || referrer == "-" {
		return ""
	}

	u, err := url.Parse(referrer)
	if err != nil || u.Hostname() == "" {
		return ""
	}

	return strings.ToLower(u.Hostname())
}

func browserFamily(userAgent string) string {
	ua := strings.ToLower(userAgent)

	switch {
	case strings.Contains(ua, "bot"), strings.Contains(ua, "spider"), strings.Contains(ua, "crawl"), strings.Contains(ua, "slurp"):
		return "Bots and crawlers"
	case strings.Contains(ua, "edg/"), strings.Contains(ua, "edge/"):
		return "Edge"
	case strings.Contains(ua, "firefox/"):
		return "Firefox"
	case strings.Contains(ua, "chrome/"), strings.Contains(ua, "crios/"):
		return "Chrome"
	case strings.Contains(ua, "safari/"):
		return "Safari"
	case strings.Contains(ua, "curl/"), strings.Contains(ua, "wget/"), strings.Contains(ua, "python"), strings.Contains(ua, "go-http"):
		return "Command line and scripts"
	case ua == "" || ua == "-":
		return "Unknown"
	}

	return "Other"
}

func topCounts(counts map[string]int) []countedItem {
	items := make([]countedItem, 0, len(counts))
	for name, count := range counts {
		items = append(items, countedItem{Name: name, Count: count})
	}

	sort.Slice(items, func(i, j int) bool {
		if items[i].Count != items[j].Count {
			return items[i].Count > items[j].Count
		}

		return items[i].Name < items[j].Name
	})

	if len(items) > topN {
		items = items[:topN]
	}

	return items
}
