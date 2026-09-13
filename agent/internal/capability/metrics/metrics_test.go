package metrics_test

import (
	"context"
	"crypto/rand"
	"encoding/json"
	"fmt"
	"os"
	"path/filepath"
	"strings"
	"testing"
	"time"

	"github.com/mikho/LESta/agent/internal/capability/metrics"
	"github.com/mikho/LESta/agent/internal/protocol"
)

func newTestUUID() string {
	var b [16]byte
	if _, err := rand.Read(b[:]); err != nil {
		panic(fmt.Sprintf("generating test UUID: %v", err))
	}

	b[6] = (b[6] & 0x0f) | 0x40
	b[8] = (b[8] & 0x3f) | 0x80

	return fmt.Sprintf("%x-%x-%x-%x-%x", b[0:4], b[4:6], b[6:8], b[8:10], b[10:16])
}

func newOp(operation protocol.Operation, payload map[string]any) protocol.OperationEnvelope {
	if payload == nil {
		payload = map[string]any{}
	}

	raw, err := json.Marshal(payload)
	if err != nil {
		panic(fmt.Sprintf("marshaling test payload: %v", err))
	}

	now := time.Now().UTC()

	return protocol.OperationEnvelope{
		ProtocolVersion:     "1",
		Capability:          "metrics.usage.v1",
		Operation:           operation,
		ResourceID:          newTestUUID(),
		DesiredStateVersion: 1,
		IdempotencyKey:      newTestUUID(),
		CorrelationID:       newTestUUID(),
		Deadline:            now.Add(30 * time.Second),
		IssuedAt:            now,
		RequestDigest:       "sha256:" + strings.Repeat("0", 64),
		Payload:             raw,
	}
}

func requireStatus(t *testing.T, label string, result protocol.ResultEnvelope, err error, want protocol.Status) {
	t.Helper()

	if err != nil {
		t.Fatalf("%s: Apply returned an error (no verdict reached): %v", label, err)
	}
	if result.Status != want {
		t.Fatalf("%s: expected status %s, got %s (errors=%+v)", label, want, result.Status, result.Errors)
	}
}

func requireErrorCode(t *testing.T, label string, result protocol.ResultEnvelope, code string) {
	t.Helper()

	for _, e := range result.Errors {
		if e.Code == code {
			return
		}
	}

	t.Fatalf("%s: expected an error with code %q, got %+v", label, code, result.Errors)
}

func TestApplyRejectsAnUnsupportedOperation(t *testing.T) {
	capability := metrics.New(metrics.Config{})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationCreate, nil))
	requireStatus(t, "create", result, err, protocol.StatusRejected)
	requireErrorCode(t, "create", result, "unsupported_operation")
}

func TestApplyRejectsAMalformedMailAccountResourceUUID(t *testing.T) {
	capability := metrics.New(metrics.Config{})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, map[string]any{
		"mail_accounts": []map[string]any{{"resource_uuid": "not-a-uuid", "domain": "example.com", "local_part": "sales"}},
	}))
	requireStatus(t, "malformed resource_uuid", result, err, protocol.StatusRejected)
	requireErrorCode(t, "malformed resource_uuid", result, "invalid_resource_uuid")
}

func TestApplyRejectsAMalformedDomain(t *testing.T) {
	capability := metrics.New(metrics.Config{})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, map[string]any{
		"mail_accounts": []map[string]any{{"resource_uuid": newTestUUID(), "domain": "NOT VALID", "local_part": "sales"}},
	}))
	requireStatus(t, "malformed domain", result, err, protocol.StatusRejected)
	requireErrorCode(t, "malformed domain", result, "invalid_domain")
}

func TestApplyRejectsAMalformedStatsPassword(t *testing.T) {
	capability := metrics.New(metrics.Config{})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, map[string]any{
		"tenant_databases": []map[string]any{{
			"resource_uuid":  newTestUUID(),
			"database_name":  "lesta_1_app1",
			"stats_user":     "lesta_1_app1_ro",
			"stats_password": "too-short",
		}},
	}))
	requireStatus(t, "malformed stats_password", result, err, protocol.StatusRejected)
	requireErrorCode(t, "malformed stats_password", result, "invalid_stats_password")
}

func TestApplyRejectsAnInvalidWebServer(t *testing.T) {
	capability := metrics.New(metrics.Config{})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, map[string]any{
		"web_resources": []map[string]any{{"resource_uuid": newTestUUID(), "web_server": "lighttpd"}},
	}))
	requireStatus(t, "invalid web_server", result, err, protocol.StatusRejected)
	requireErrorCode(t, "invalid web_server", result, "invalid_web_server")
}

func TestApplyReportsRealMailboxDiskUsage(t *testing.T) {
	vmailRoot := t.TempDir()
	resourceID := newTestUUID()

	maildir := filepath.Join(vmailRoot, "example.com", "sales", "cur")
	if err := os.MkdirAll(maildir, 0o755); err != nil {
		t.Fatalf("creating maildir: %v", err)
	}

	content := strings.Repeat("x", 1000)
	if err := os.WriteFile(filepath.Join(maildir, "message1"), []byte(content), 0o644); err != nil {
		t.Fatalf("writing message1: %v", err)
	}
	if err := os.WriteFile(filepath.Join(maildir, "message2"), []byte(content), 0o644); err != nil {
		t.Fatalf("writing message2: %v", err)
	}

	capability := metrics.New(metrics.Config{VmailRoot: vmailRoot})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, map[string]any{
		"mail_accounts": []map[string]any{{"resource_uuid": resourceID, "domain": "example.com", "local_part": "sales"}},
	}))
	requireStatus(t, "real mailbox usage", result, err, protocol.StatusApplied)

	var data struct {
		MailAccounts []struct {
			ResourceUUID string `json:"resource_uuid"`
			DiskBytes    int64  `json:"disk_bytes"`
		} `json:"mail_accounts"`
	}
	if err := json.Unmarshal(result.Data, &data); err != nil {
		t.Fatalf("decoding Data: %v", err)
	}

	if len(data.MailAccounts) != 1 || data.MailAccounts[0].ResourceUUID != resourceID {
		t.Fatalf("expected exactly one mail account usage entry for %s, got %+v", resourceID, data.MailAccounts)
	}
	if data.MailAccounts[0].DiskBytes != 2000 {
		t.Fatalf("expected disk_bytes=2000 (two real 1000-byte files), got %d", data.MailAccounts[0].DiskBytes)
	}
}

func TestApplyReportsZeroForAMailboxThatHasNeverReceivedMail(t *testing.T) {
	capability := metrics.New(metrics.Config{VmailRoot: t.TempDir()})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, map[string]any{
		"mail_accounts": []map[string]any{{"resource_uuid": newTestUUID(), "domain": "example.com", "local_part": "nobody"}},
	}))
	requireStatus(t, "never-delivered mailbox", result, err, protocol.StatusApplied)

	var data struct {
		MailAccounts []struct {
			DiskBytes int64 `json:"disk_bytes"`
		} `json:"mail_accounts"`
	}
	if err := json.Unmarshal(result.Data, &data); err != nil {
		t.Fatalf("decoding Data: %v", err)
	}

	if len(data.MailAccounts) != 1 || data.MailAccounts[0].DiskBytes != 0 {
		t.Fatalf("expected a single zero-byte entry, got %+v", data.MailAccounts)
	}
}

func TestApplyCollectsRealWebUsageAndTracksOffsetAcrossCalls(t *testing.T) {
	logDir := t.TempDir()
	offsetRoot := t.TempDir()
	resourceID := newTestUUID()

	logPath := filepath.Join(logDir, resourceID+".access.log")
	line := `127.0.0.1 - - [13/Sep/2026:00:00:00 +0000] "GET / HTTP/1.1" 200 100 "-" "curl/8.0"` + "\n"
	if err := os.WriteFile(logPath, []byte(line+line), 0o644); err != nil {
		t.Fatalf("writing access log: %v", err)
	}

	capability := metrics.New(metrics.Config{NginxLogDir: logDir, OffsetStateRoot: offsetRoot})

	first, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, map[string]any{
		"web_resources": []map[string]any{{"resource_uuid": resourceID, "web_server": "nginx"}},
	}))
	requireStatus(t, "first web collection", first, err, protocol.StatusApplied)

	var firstData struct {
		WebResources []struct {
			RequestCount int64 `json:"request_count"`
			BytesSent    int64 `json:"bytes_sent"`
		} `json:"web_resources"`
	}
	if err := json.Unmarshal(first.Data, &firstData); err != nil {
		t.Fatalf("decoding first Data: %v", err)
	}

	if len(firstData.WebResources) != 1 || firstData.WebResources[0].RequestCount != 2 || firstData.WebResources[0].BytesSent != 200 {
		t.Fatalf("expected 2 requests / 200 bytes from the two real log lines, got %+v", firstData.WebResources)
	}

	// A second collection with no new lines appended must report zero new
	// usage: the offset was durably recorded after the first call.
	second, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, map[string]any{
		"web_resources": []map[string]any{{"resource_uuid": resourceID, "web_server": "nginx"}},
	}))
	requireStatus(t, "second web collection (no new lines)", second, err, protocol.StatusApplied)

	var secondData struct {
		WebResources []struct {
			RequestCount int64 `json:"request_count"`
		} `json:"web_resources"`
	}
	if err := json.Unmarshal(second.Data, &secondData); err != nil {
		t.Fatalf("decoding second Data: %v", err)
	}

	if secondData.WebResources[0].RequestCount != 0 {
		t.Fatalf("expected 0 new requests on the second collection, got %d", secondData.WebResources[0].RequestCount)
	}

	// Append one more real line, then collect again: only the new line
	// should be counted, proving the offset genuinely advanced rather than
	// resetting to 0 every call.
	f, err := os.OpenFile(logPath, os.O_APPEND|os.O_WRONLY, 0o644)
	if err != nil {
		t.Fatalf("opening log for append: %v", err)
	}
	if _, err := f.WriteString(line); err != nil {
		t.Fatalf("appending line: %v", err)
	}
	f.Close()

	third, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, map[string]any{
		"web_resources": []map[string]any{{"resource_uuid": resourceID, "web_server": "nginx"}},
	}))
	requireStatus(t, "third web collection (one new line)", third, err, protocol.StatusApplied)

	var thirdData struct {
		WebResources []struct {
			RequestCount int64 `json:"request_count"`
		} `json:"web_resources"`
	}
	if err := json.Unmarshal(third.Data, &thirdData); err != nil {
		t.Fatalf("decoding third Data: %v", err)
	}

	if thirdData.WebResources[0].RequestCount != 1 {
		t.Fatalf("expected exactly 1 new request after appending one real line, got %d", thirdData.WebResources[0].RequestCount)
	}
}

func TestApplyTreatsARotatedShorterLogAsFreshRatherThanSeekingPastEOF(t *testing.T) {
	logDir := t.TempDir()
	offsetRoot := t.TempDir()
	resourceID := newTestUUID()

	logPath := filepath.Join(logDir, resourceID+".access.log")
	line := `127.0.0.1 - - [13/Sep/2026:00:00:00 +0000] "GET / HTTP/1.1" 200 500 "-" "curl/8.0"` + "\n"

	if err := os.WriteFile(logPath, []byte(strings.Repeat(line, 5)), 0o644); err != nil {
		t.Fatalf("writing initial access log: %v", err)
	}

	capability := metrics.New(metrics.Config{NginxLogDir: logDir, OffsetStateRoot: offsetRoot})

	first, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, map[string]any{
		"web_resources": []map[string]any{{"resource_uuid": resourceID, "web_server": "nginx"}},
	}))
	requireStatus(t, "pre-rotation collection", first, err, protocol.StatusApplied)

	// Simulate logrotate's own copytruncate: the file shrinks to a single
	// fresh line, well below the offset already recorded above.
	if err := os.WriteFile(logPath, []byte(line), 0o644); err != nil {
		t.Fatalf("simulating copytruncate: %v", err)
	}

	second, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, map[string]any{
		"web_resources": []map[string]any{{"resource_uuid": resourceID, "web_server": "nginx"}},
	}))
	requireStatus(t, "post-rotation collection", second, err, protocol.StatusApplied)

	var data struct {
		WebResources []struct {
			RequestCount int64 `json:"request_count"`
		} `json:"web_resources"`
	}
	if err := json.Unmarshal(second.Data, &data); err != nil {
		t.Fatalf("decoding Data: %v", err)
	}

	if data.WebResources[0].RequestCount != 1 {
		t.Fatalf("expected the single post-rotation line to be counted (offset reset to 0), got %d", data.WebResources[0].RequestCount)
	}
}

func TestApplyReportsZeroForAWebResourceWithNoLogYet(t *testing.T) {
	capability := metrics.New(metrics.Config{NginxLogDir: t.TempDir(), OffsetStateRoot: t.TempDir()})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, map[string]any{
		"web_resources": []map[string]any{{"resource_uuid": newTestUUID(), "web_server": "nginx"}},
	}))
	requireStatus(t, "no log yet", result, err, protocol.StatusApplied)

	var data struct {
		WebResources []struct {
			RequestCount int64 `json:"request_count"`
		} `json:"web_resources"`
	}
	if err := json.Unmarshal(result.Data, &data); err != nil {
		t.Fatalf("decoding Data: %v", err)
	}

	if data.WebResources[0].RequestCount != 0 {
		t.Fatalf("expected 0 requests for a resource with no log file yet, got %d", data.WebResources[0].RequestCount)
	}
}

func TestApplyIgnoresMalformedLogLinesRatherThanFailing(t *testing.T) {
	logDir := t.TempDir()
	resourceID := newTestUUID()

	logPath := filepath.Join(logDir, resourceID+".access.log")
	goodLine := `127.0.0.1 - - [13/Sep/2026:00:00:00 +0000] "GET / HTTP/1.1" 200 42 "-" "curl/8.0"` + "\n"
	content := "\n" + goodLine + "this is not a real access log line\n" + goodLine
	if err := os.WriteFile(logPath, []byte(content), 0o644); err != nil {
		t.Fatalf("writing access log: %v", err)
	}

	capability := metrics.New(metrics.Config{NginxLogDir: logDir, OffsetStateRoot: t.TempDir()})

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, map[string]any{
		"web_resources": []map[string]any{{"resource_uuid": resourceID, "web_server": "nginx"}},
	}))
	requireStatus(t, "log with malformed lines", result, err, protocol.StatusApplied)

	var data struct {
		WebResources []struct {
			RequestCount int64 `json:"request_count"`
			BytesSent    int64 `json:"bytes_sent"`
		} `json:"web_resources"`
	}
	if err := json.Unmarshal(result.Data, &data); err != nil {
		t.Fatalf("decoding Data: %v", err)
	}

	if data.WebResources[0].RequestCount != 2 || data.WebResources[0].BytesSent != 84 {
		t.Fatalf("expected the 2 real lines counted and the malformed/blank ones skipped, got %+v", data.WebResources[0])
	}
}

func TestApplyReportsDegradedWhenOneResourceFailsButOthersSucceed(t *testing.T) {
	vmailRoot := t.TempDir()

	// A path component containing a NUL byte is impossible to express as a
	// valid domain/local_part through ParsePayload's own regexes, so this
	// forces a real, well-formed measurement failure a different way: point
	// VmailRoot at a real FILE (not a directory), which makes any lookup
	// beneath it a genuine "not a directory" os error, never a mere
	// "doesn't exist yet" zero.
	blockingFile := filepath.Join(vmailRoot, "not-a-directory")
	if err := os.WriteFile(blockingFile, []byte("x"), 0o644); err != nil {
		t.Fatalf("writing blocking file: %v", err)
	}

	capability := metrics.New(metrics.Config{VmailRoot: blockingFile, NginxLogDir: t.TempDir(), OffsetStateRoot: t.TempDir()})

	goodResourceID := newTestUUID()

	result, err := capability.Apply(context.Background(), newOp(protocol.OperationObserve, map[string]any{
		"mail_accounts": []map[string]any{{"resource_uuid": newTestUUID(), "domain": "example.com", "local_part": "sales"}},
		"web_resources": []map[string]any{{"resource_uuid": goodResourceID, "web_server": "nginx"}},
	}))
	requireStatus(t, "one resource fails, one succeeds", result, err, protocol.StatusDegraded)
	requireErrorCode(t, "one resource fails, one succeeds", result, "mailbox_measurement_failed")

	var data struct {
		WebResources []struct {
			ResourceUUID string `json:"resource_uuid"`
		} `json:"web_resources"`
	}
	if err := json.Unmarshal(result.Data, &data); err != nil {
		t.Fatalf("decoding Data: %v", err)
	}

	if len(data.WebResources) != 1 || data.WebResources[0].ResourceUUID != goodResourceID {
		t.Fatalf("expected the web resource to still be measured despite the mailbox failure, got %+v", data.WebResources)
	}
}

func TestApplyDuplicateIdempotencyKeyReplaysTheSameReceipt(t *testing.T) {
	vmailRoot := t.TempDir()
	resourceID := newTestUUID()

	maildir := filepath.Join(vmailRoot, "example.com", "sales", "cur")
	if err := os.MkdirAll(maildir, 0o755); err != nil {
		t.Fatalf("creating maildir: %v", err)
	}
	if err := os.WriteFile(filepath.Join(maildir, "message1"), []byte("hello"), 0o644); err != nil {
		t.Fatalf("writing message1: %v", err)
	}

	capability := metrics.New(metrics.Config{VmailRoot: vmailRoot})

	op := newOp(protocol.OperationObserve, map[string]any{
		"mail_accounts": []map[string]any{{"resource_uuid": resourceID, "domain": "example.com", "local_part": "sales"}},
	})

	first, err := capability.Apply(context.Background(), op)
	requireStatus(t, "first call", first, err, protocol.StatusApplied)

	// A second real message is delivered between the two calls; a genuine
	// re-collection would see 10 bytes, but a duplicate idempotency key
	// must replay the FIRST call's own receipt unchanged, never re-run the
	// measurement.
	if err := os.WriteFile(filepath.Join(maildir, "message2"), []byte("hello"), 0o644); err != nil {
		t.Fatalf("writing message2: %v", err)
	}

	second, err := capability.Apply(context.Background(), op)
	requireStatus(t, "duplicate idempotency key", second, err, protocol.StatusApplied)

	if string(second.Data) != string(first.Data) {
		t.Fatalf("expected a duplicate idempotency key to replay the exact same Data, got first=%s second=%s", first.Data, second.Data)
	}
}
