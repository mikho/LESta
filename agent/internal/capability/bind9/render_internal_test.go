package bind9

// This file is deliberately package bind9 (not bind9_test): the rendering
// helpers it exercises (renderRecordLine, escapeZoneString) are unexported,
// and these are pure-function tests that need no disposable BIND9 instance at
// all. Every other test in this package lives in bind9_test.go as external,
// black-box tests against the exported Bind9Capability/Payload/Record API;
// this file is the deliberate, narrow exception, so those internals don't
// need to be exported just to be tested directly.

import (
	"errors"
	"testing"
)

func intPtr(n int) *int { return &n }

func TestRenderRecordLine_MXFieldOrderAndFQDNNormalization(t *testing.T) {
	line, err := renderRecordLine(Record{Name: "@", Type: "MX", Priority: intPtr(10), Value: "mail.example.com"})
	if err != nil {
		t.Fatalf("renderRecordLine: %v", err)
	}

	want := "@ IN MX 10 mail.example.com."
	if line != want {
		t.Fatalf("MX line: got %q, want %q", line, want)
	}

	// Value already FQDN (trailing dot) must not gain a second one.
	line2, err := renderRecordLine(Record{Name: "@", Type: "MX", Priority: intPtr(10), Value: "mail.example.com."})
	if err != nil {
		t.Fatalf("renderRecordLine: %v", err)
	}
	if line2 != want {
		t.Fatalf("MX line (already fqdn): got %q, want %q", line2, want)
	}
}

func TestRenderRecordLine_SRVProducesPriorityWeightPortTarget(t *testing.T) {
	line, err := renderRecordLine(Record{
		Name:     "_sip._tcp",
		Type:     "SRV",
		Priority: intPtr(20),
		Value:    "10 5060 sipserver.example.com.",
	})
	if err != nil {
		t.Fatalf("renderRecordLine: %v", err)
	}

	want := "_sip._tcp IN SRV 20 10 5060 sipserver.example.com."
	if line != want {
		t.Fatalf("SRV line: got %q, want %q", line, want)
	}
}

func TestRenderRecordLine_CAARendersVerbatim(t *testing.T) {
	line, err := renderRecordLine(Record{Name: "@", Type: "CAA", Value: `0 issue "letsencrypt.org"`})
	if err != nil {
		t.Fatalf("renderRecordLine: %v", err)
	}

	want := `@ IN CAA 0 issue "letsencrypt.org"`
	if line != want {
		t.Fatalf("CAA line: got %q, want %q (CAA must render fully verbatim, unescaped)", line, want)
	}
}

func TestRenderRecordLine_NSAndCNAMEAndPTRNormalizeFQDN(t *testing.T) {
	cases := []struct {
		recordType string
		value      string
		want       string
	}{
		{"NS", "ns1.example.com", "@ IN NS ns1.example.com."},
		{"CNAME", "target.example.com", "@ IN CNAME target.example.com."},
		{"PTR", "host.example.com", "@ IN PTR host.example.com."},
	}

	for _, tc := range cases {
		line, err := renderRecordLine(Record{Name: "@", Type: tc.recordType, Value: tc.value})
		if err != nil {
			t.Fatalf("%s: renderRecordLine: %v", tc.recordType, err)
		}
		if line != tc.want {
			t.Fatalf("%s line: got %q, want %q", tc.recordType, line, tc.want)
		}
	}
}

func TestRenderRecordLine_AAndAAAADoNotNormalize(t *testing.T) {
	line, err := renderRecordLine(Record{Name: "www", Type: "A", Value: "203.0.113.10"})
	if err != nil {
		t.Fatalf("renderRecordLine: %v", err)
	}
	if want := "www IN A 203.0.113.10"; line != want {
		t.Fatalf("A line: got %q, want %q", line, want)
	}

	line6, err := renderRecordLine(Record{Name: "www", Type: "AAAA", Value: "2001:db8::1"})
	if err != nil {
		t.Fatalf("renderRecordLine: %v", err)
	}
	if want := "www IN AAAA 2001:db8::1"; line6 != want {
		t.Fatalf("AAAA line: got %q, want %q", line6, want)
	}
}

func TestEscapeZoneString_EscapesBackslashThenQuote(t *testing.T) {
	// Order matters: escaping backslash first, then quote, means the
	// backslashes inserted for the quote escape are never themselves
	// re-escaped by a second backslash pass.
	got := escapeZoneString(`say "hi"\there`)
	want := `say \"hi\"\\there`
	if got != want {
		t.Fatalf("escapeZoneString: got %q, want %q", got, want)
	}
}

func TestRenderRecordLine_TXTEscapesEmbeddedQuoteAndBackslash(t *testing.T) {
	line, err := renderRecordLine(Record{Name: "@", Type: "TXT", Value: `v=spf1 include:"weird\path" ~all`})
	if err != nil {
		t.Fatalf("renderRecordLine: %v", err)
	}

	want := `@ IN TXT "v=spf1 include:\"weird\\path\" ~all"`
	if line != want {
		t.Fatalf("TXT line: got %q, want %q", line, want)
	}
}

func TestSortRecords_StableByNameTypePriorityValue(t *testing.T) {
	records := []Record{
		{Name: "www", Type: "A", Value: "10.0.0.2"},
		{Name: "www", Type: "A", Value: "10.0.0.1"},
		{Name: "@", Type: "MX", Priority: intPtr(20), Value: "mx2.example.com."},
		{Name: "@", Type: "MX", Priority: intPtr(10), Value: "mx1.example.com."},
		{Name: "@", Type: "A", Value: "10.0.0.1"},
	}

	sortRecords(records)

	var order []string
	for _, r := range records {
		order = append(order, r.Name+"/"+r.Type+"/"+r.Value)
	}

	want := []string{
		"@/A/10.0.0.1",
		"@/MX/mx1.example.com.",
		"@/MX/mx2.example.com.",
		"www/A/10.0.0.1",
		"www/A/10.0.0.2",
	}

	if len(order) != len(want) {
		t.Fatalf("sortRecords: got %v, want %v", order, want)
	}
	for i := range want {
		if order[i] != want[i] {
			t.Fatalf("sortRecords: got %v, want %v", order, want)
		}
	}
}

func TestRenderZoneData_RequiresNonEmptyNameservers(t *testing.T) {
	_, err := renderZoneData("res-1", Payload{Domain: "example.test", TTL: 3600}, Config{}, 1)
	if err == nil {
		t.Fatalf("expected a plain Go error when Config.Nameservers is empty, got nil")
	}

	var ve *ValidationError
	if errors.As(err, &ve) {
		t.Fatalf("expected a plain Go rendering error, not a *ValidationError: %v", ve)
	}
}
