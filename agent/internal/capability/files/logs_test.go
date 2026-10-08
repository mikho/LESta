package files

import (
	"encoding/base64"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

const testResource = "6a28cbb5-267d-4ce5-a532-39b2c7fd096b"

func writeLog(t *testing.T, kind, content string) Config {
	t.Helper()

	dir := t.TempDir()
	if err := os.WriteFile(filepath.Join(dir, testResource+"."+kind+".log"), []byte(content), 0o644); err != nil {
		t.Fatalf("writing the log: %v", err)
	}

	return Config{LogDirs: []string{t.TempDir(), dir}}
}

func TestLogTailReturnsTheLastLinesAndMarksTruncation(t *testing.T) {
	var b strings.Builder
	for i := 1; i <= 300; i++ {
		fmt.Fprintf(&b, "line %d\n", i)
	}

	cfg := writeLog(t, "error", b.String())

	data, code, message := observeLog(cfg, testResource, Payload{LogKind: "error", LogMode: "tail", LogLines: 50})
	if data == nil {
		t.Fatalf("tail rejected: %s %s", code, message)
	}

	var out struct {
		Lines     []string `json:"lines"`
		Truncated bool     `json:"truncated"`
	}
	if err := json.Unmarshal(data, &out); err != nil {
		t.Fatalf("decoding: %v", err)
	}

	if len(out.Lines) != 50 || out.Lines[0] != "line 251" || out.Lines[49] != "line 300" || !out.Truncated {
		t.Errorf("unexpected tail: %d lines, first %q, last %q, truncated %v", len(out.Lines), out.Lines[0], out.Lines[len(out.Lines)-1], out.Truncated)
	}
}

func TestLogSummaryCountsStatusPagesReferrersAndVisitors(t *testing.T) {
	lines := []string{
		`203.0.113.1 - - [08/Oct/2026:10:01:02 +0000] "GET /index.html?x=1 HTTP/1.1" 200 1000 "https://news.example/story" "Mozilla/5.0 Chrome/120.0 Safari/537.36"`,
		`203.0.113.1 - - [08/Oct/2026:10:05:00 +0000] "GET /index.html HTTP/1.1" 200 500 "-" "Mozilla/5.0 Chrome/120.0 Safari/537.36"`,
		`203.0.113.2 - - [08/Oct/2026:11:00:00 +0000] "GET /missing HTTP/1.1" 404 150 "-" "Googlebot/2.1"`,
		`203.0.113.3 - - [08/Oct/2026:11:30:00 +0000] "GET /old HTTP/1.1" 301 0 "-" "Mozilla/5.0 Firefox/121.0"`,
		`203.0.113.3 - - [08/Oct/2026:12:30:00 +0000] "POST /form HTTP/1.1" 500 80 "-" "curl/8.0"`,
		`garbage line that is not a log entry`,
	}

	cfg := writeLog(t, "access", strings.Join(lines, "\n")+"\n")

	data, code, message := observeLog(cfg, testResource, Payload{LogKind: "access", LogMode: "summary"})
	if data == nil {
		t.Fatalf("summary rejected: %s %s", code, message)
	}

	var s trafficSummary
	if err := json.Unmarshal(data, &s); err != nil {
		t.Fatalf("decoding: %v", err)
	}

	if s.Requests != 5 || s.Visitors != 3 || s.BytesSent != 1730 {
		t.Errorf("totals: requests %d visitors %d bytes %d", s.Requests, s.Visitors, s.BytesSent)
	}

	if s.Status["2xx"] != 2 || s.Status["3xx"] != 1 || s.Status["4xx"] != 1 || s.Status["5xx"] != 1 {
		t.Errorf("status: %v", s.Status)
	}

	if len(s.TopPages) != 1 || s.TopPages[0].Name != "/index.html" || s.TopPages[0].Count != 2 {
		t.Errorf("top pages (query stripped, 2xx only): %+v", s.TopPages)
	}

	if len(s.TopNotFound) != 1 || s.TopNotFound[0].Name != "/missing" {
		t.Errorf("top not found: %+v", s.TopNotFound)
	}

	if len(s.TopReferrers) != 1 || s.TopReferrers[0].Name != "news.example" {
		t.Errorf("referrers: %+v", s.TopReferrers)
	}

	got := map[string]int{}
	for _, b := range s.Browsers {
		got[b.Name] = b.Count
	}

	if got["Chrome"] != 2 || got["Bots and crawlers"] != 1 || got["Firefox"] != 1 || got["Command line and scripts"] != 1 {
		t.Errorf("browsers: %v", got)
	}

	if s.ByHour[10] != 2 || s.ByHour[11] != 2 || s.ByHour[12] != 1 {
		t.Errorf("by hour: %v", s.ByHour)
	}
}

func TestLogDownloadReturnsTheLogContent(t *testing.T) {
	cfg := writeLog(t, "access", "a\nb\n")

	data, code, message := observeLog(cfg, testResource, Payload{LogKind: "access", LogMode: "download"})
	if data == nil {
		t.Fatalf("download rejected: %s %s", code, message)
	}

	var out struct {
		Filename string `json:"filename"`
		Content  string `json:"content_base64"`
	}
	if err := json.Unmarshal(data, &out); err != nil {
		t.Fatalf("decoding: %v", err)
	}

	decoded, _ := base64.StdEncoding.DecodeString(out.Content)

	if out.Filename != "access.log" || string(decoded) != "a\nb\n" {
		t.Errorf("unexpected download: %q %q", out.Filename, decoded)
	}
}

func TestLogRequestsAreRefusedWhenUnsafeOrMissing(t *testing.T) {
	cfg := writeLog(t, "access", "x\n")

	if data, _, _ := observeLog(cfg, "../../etc/passwd", Payload{LogKind: "access", LogMode: "tail"}); data != nil {
		t.Errorf("a non-UUID resource id must be refused")
	}

	if data, _, _ := observeLog(cfg, "11111111-1111-1111-1111-111111111111", Payload{LogKind: "access", LogMode: "tail"}); data != nil {
		t.Errorf("a domain without a log must be refused")
	}

	if data, _, _ := observeLog(cfg, testResource, Payload{LogKind: "error", LogMode: "summary"}); data != nil {
		t.Errorf("an error-log summary must be refused")
	}

	for name, raw := range map[string]string{
		"unknown kind":     `{"account_username":"lesta-t1","log_kind":"../shadow","log_mode":"tail"}`,
		"log with a path":  `{"account_username":"lesta-t1","log_kind":"access","log_mode":"tail","path":"x"}`,
		"log with content": `{"account_username":"lesta-t1","log_kind":"access","log_mode":"tail","content_base64":"eA=="}`,
	} {
		if _, err := ParsePayload(json.RawMessage(raw)); err == nil {
			t.Errorf("%s: expected a rejection", name)
		}
	}
}
