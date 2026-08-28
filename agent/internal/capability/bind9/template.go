package bind9

import (
	"bytes"
	"embed"
	"fmt"
	"sort"
	"strings"
	"text/template"
)

//go:embed templates/zone.db.tmpl templates/stanza.conf.tmpl
var templateFS embed.FS

// SOA fields the Laravel payload never carries: fixed Go constants, not
// Config fields, since there is no secondary this phase (no AXFR consumer),
// so these values are never operator-tunable input.
const (
	soaRefresh = 3600    // 1 hour
	soaRetry   = 900     // 15 minutes
	soaExpire  = 1209600 // 14 days
)

// zoneData is the substitution set for zone.db.tmpl. Every field is either
// already-validated payload data or an agent-computed value; tenant input
// never selects which template file gets parsed, it only ever fills
// already-validated placeholders (Records is pre-rendered, already-escaped,
// already-sorted lines, computed by renderZoneData below).
type zoneData struct {
	ResourceID  string
	Domain      string
	TTL         int
	Serial      uint32
	Nameservers []string
	RNAME       string
	Refresh     int
	Retry       int
	Expire      int
	Minimum     int
	Marker      string
	Records     []string
}

// stanzaData is the substitution set for stanza.conf.tmpl.
type stanzaData struct {
	ResourceID string
	Domain     string
	ZoneDBPath string
}

// fqdn appends a trailing "." if s doesn't already have one. DnsRecord names
// (stored relative to the zone, e.g. "www" or "@") are never touched by this;
// it only ever normalizes hostname-shaped record *values* (NS/CNAME/PTR/MX
// targets) that must be absolute in the zone file, and Config.Nameservers.
func fqdn(s string) string {
	if strings.HasSuffix(s, ".") {
		return s
	}

	return s + "."
}

// escapeZoneString escapes backslash then quote, in that order, so the
// backslashes this function inserts are never themselves re-escaped by the
// second pass. Applied to TXT values, which this package's own template
// wraps in quotes it constructs itself: an embedded quote or backslash in the
// tenant-supplied value would otherwise break out of that quoting.
func escapeZoneString(s string) string {
	s = strings.ReplaceAll(s, `\`, `\\`)
	s = strings.ReplaceAll(s, `"`, `\"`)

	return s
}

// renderRecordLine renders one already-validated Record into its zone-file
// presentation-format line. r.Type is guaranteed (by ParsePayload, called
// before this is ever reached) to be one of the 9 supported types, and
// r.Priority is guaranteed non-nil for MX/SRV and nil otherwise; the nil
// checks below are defense in depth, not the primary enforcement point.
func renderRecordLine(r Record) (string, error) {
	switch r.Type {
	case "A":
		return fmt.Sprintf("%s IN A %s", r.Name, r.Value), nil
	case "AAAA":
		return fmt.Sprintf("%s IN AAAA %s", r.Name, r.Value), nil
	case "NS":
		return fmt.Sprintf("%s IN NS %s", r.Name, fqdn(r.Value)), nil
	case "CNAME":
		return fmt.Sprintf("%s IN CNAME %s", r.Name, fqdn(r.Value)), nil
	case "PTR":
		return fmt.Sprintf("%s IN PTR %s", r.Name, fqdn(r.Value)), nil
	case "MX":
		if r.Priority == nil {
			return "", fmt.Errorf("MX record %q has no priority", r.Name)
		}

		return fmt.Sprintf("%s IN MX %d %s", r.Name, *r.Priority, fqdn(r.Value)), nil
	case "SRV":
		// value is already required (the opaque-string contract) to be the
		// "weight port target" triplet, so priority + " " + value produces
		// exactly BIND's real "priority weight port target" syntax. The
		// target sub-field inside value is not FQDN-normalized here: this
		// code has no way to know where it starts within the opaque string.
		if r.Priority == nil {
			return "", fmt.Errorf("SRV record %q has no priority", r.Name)
		}

		return fmt.Sprintf("%s IN SRV %d %s", r.Name, *r.Priority, r.Value), nil
	case "CAA":
		// Rendered fully verbatim, deliberately unescaped. value is already
		// required (the same opaque-string contract as SRV) to be the
		// complete "flag tag \"value\"" triplet, including its own literal
		// quote characters as part of CAA's required presentation syntax.
		// Unlike TXT below, where the whole value becomes the content of a
		// single quoted string *this template constructs*, CAA's quotes are
		// not ours to add or escape: escaping them here would double-encode
		// an already well-formed tenant-supplied record and corrupt its
		// meaning. named-checkconf -z is the backstop for any resulting
		// zone-file syntax problem, exactly as for every other opaque-string
		// field.
		return fmt.Sprintf("%s IN CAA %s", r.Name, r.Value), nil
	case "TXT":
		return fmt.Sprintf("%s IN TXT \"%s\"", r.Name, escapeZoneString(r.Value)), nil
	default:
		return "", fmt.Errorf("unsupported record type %q", r.Type)
	}
}

// priorityKey gives a stable, total-ordering sort key for Priority: nil
// (every non-MX/SRV type) sorts before any real priority value.
func priorityKey(p *int) int {
	if p == nil {
		return -1
	}

	return *p
}

// sortRecords sorts records in place by a stable key: name, then type, then
// priority, then value. DnsZone::records() has no orderBy, so this keeps
// rendering (and its digest) immune to incidental row order.
func sortRecords(records []Record) {
	sort.SliceStable(records, func(i, j int) bool {
		a, b := records[i], records[j]

		if a.Name != b.Name {
			return a.Name < b.Name
		}
		if a.Type != b.Type {
			return a.Type < b.Type
		}
		if pa, pb := priorityKey(a.Priority), priorityKey(b.Priority); pa != pb {
			return pa < pb
		}

		return a.Value < b.Value
	})
}

// renderZoneData renders the full zone file content for resourceID's
// payload, at generation n. Config.Nameservers (fixed, out-of-bailiwick
// FQDNs) is synthesized into the zone's apex NS set and SOA MNAME, which is
// what lets a zone with zero DnsRecord rows still validate and serve. The
// generation number itself (not a YYYYMMDDNN-style date serial) is used as
// the SOA serial: NextGeneration's own contract guarantees strict
// per-resource monotonicity, so this needs no clock read.
func renderZoneData(resourceID string, payload Payload, cfg Config, n int) ([]byte, error) {
	if len(cfg.Nameservers) == 0 {
		return nil, fmt.Errorf("rendering zone data for %s: Config.Nameservers is empty; at least one fixed nameserver FQDN must be configured", resourceID)
	}

	nameservers := make([]string, len(cfg.Nameservers))
	for i, ns := range cfg.Nameservers {
		nameservers[i] = fqdn(ns)
	}

	records := make([]Record, 0, len(payload.Records))

	for _, r := range payload.Records {
		// Per-record suspension is independent of the zone-level Suspended
		// flag: skip only records individually flagged, while still
		// rendering everything else.
		if r.Suspended {
			continue
		}

		records = append(records, r)
	}

	sortRecords(records)

	lines := make([]string, 0, len(records))

	for _, r := range records {
		line, err := renderRecordLine(r)
		if err != nil {
			return nil, fmt.Errorf("rendering zone data for %s: %w", resourceID, err)
		}

		lines = append(lines, line)
	}

	data := zoneData{
		ResourceID:  resourceID,
		Domain:      fqdn(payload.Domain),
		TTL:         payload.TTL,
		Serial:      uint32(n),
		Nameservers: nameservers,
		RNAME:       "hostmaster." + nameservers[0],
		Refresh:     soaRefresh,
		Retry:       soaRetry,
		Expire:      soaExpire,
		Minimum:     payload.TTL,
		Marker:      fmt.Sprintf("LESTA-MARKER resource=%s", resourceID),
		Records:     lines,
	}

	tmpl, err := template.New("zone.db.tmpl").ParseFS(templateFS, "templates/zone.db.tmpl")
	if err != nil {
		return nil, fmt.Errorf("parsing zone.db template: %w", err)
	}

	var buf bytes.Buffer
	if err := tmpl.Execute(&buf, data); err != nil {
		return nil, fmt.Errorf("executing zone.db template: %w", err)
	}

	return buf.Bytes(), nil
}

// renderStanza renders the LiveDir fragment that points named at zoneDBPath
// (generation n's own zone data file) for domain. The zone name is rendered
// without a trailing dot, matching conventional named.conf zone clause style
// (verified directly against a real named-checkconf -z pass).
func renderStanza(resourceID, domain, zoneDBPath string) ([]byte, error) {
	tmpl, err := template.New("stanza.conf.tmpl").ParseFS(templateFS, "templates/stanza.conf.tmpl")
	if err != nil {
		return nil, fmt.Errorf("parsing stanza template: %w", err)
	}

	var buf bytes.Buffer
	if err := tmpl.Execute(&buf, stanzaData{ResourceID: resourceID, Domain: domain, ZoneDBPath: zoneDBPath}); err != nil {
		return nil, fmt.Errorf("executing stanza template: %w", err)
	}

	return buf.Bytes(), nil
}
