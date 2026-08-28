package bind9

import (
	"bytes"
	"encoding/json"
	"fmt"
	"regexp"
)

// hostnamePattern matches a dot-separated ASCII hostname: labels of
// alphanumerics and hyphens (never leading/trailing a label with a hyphen),
// at least two labels. Deliberately duplicated from the nginx capability's
// identical pattern rather than shared: this package does not depend on
// internal/capability/nginx, matching the same "fake deliberately doesn't
// depend on the real capability it stands in for" precedent elsewhere in this
// codebase. DnsZone::normalizeDomain already lower-cases and IDN-converts on
// the Laravel side, so this only needs to validate the canonical ASCII form
// it is handed.
var hostnamePattern = regexp.MustCompile(`^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$`)

// TTL bounds mirror StoreDnsZoneRequest's own already-enforced 'min:60'/'max:604800'
// rule on the Laravel side; this is a defense-in-depth re-check, not the
// primary enforcement point.
const (
	minTTL = 60
	maxTTL = 604800
)

// reservedRecordName is the marker record name the agent itself always
// synthesizes into every rendered zone (see template.go's renderZoneData); a
// tenant record claiming this name would collide with the agent's own health
// check record, so it is rejected outright.
const reservedRecordName = "_lesta-marker"

// dnsRecordTypes mirrors App\Enums\DnsRecordType's 9 cases exactly.
var dnsRecordTypes = map[string]bool{
	"A":     true,
	"AAAA":  true,
	"NS":    true,
	"CNAME": true,
	"MX":    true,
	"TXT":   true,
	"SRV":   true,
	"PTR":   true,
	"CAA":   true,
}

// requiresPriority reports whether recordType requires a non-nil Priority,
// mirroring StoreDnsRecordRequest's own 'Rule::requiredIf(in_array($type,
// ["MX", "SRV"]))' rule.
func requiresPriority(recordType string) bool {
	return recordType == "MX" || recordType == "SRV"
}

// Record is one entry in a DnsZone's toProvisioningPayload()'s records array.
// Priority is a pointer because Laravel sends null for every non-MX/SRV type.
type Record struct {
	Name      string `json:"name"`
	Type      string `json:"type"`
	Priority  *int   `json:"priority"`
	Value     string `json:"value"`
	Suspended bool   `json:"suspended"`
}

// Payload is the dns.bind9.v1 capability's request body, matching
// DnsZone::toProvisioningPayload()'s prose shape exactly.
type Payload struct {
	Domain    string   `json:"domain"`
	TTL       int      `json:"ttl"`
	Records   []Record `json:"records"`
	Suspended bool     `json:"suspended"`
}

// ValidationError is a well-formed payload rejection: a schema-shaped (code,
// message, field) triple the caller turns directly into a rejected
// ResultEnvelope. It is never a Go error representing "no verdict was
// reached".
type ValidationError struct {
	Code    string
	Message string
	Field   string
}

func (e *ValidationError) Error() string {
	return fmt.Sprintf("%s: %s (field=%s)", e.Code, e.Message, e.Field)
}

// ParsePayload decodes and validates raw as a Payload. Unknown fields are a
// hard decode error, matching the envelope decode discipline.
//
// Deliberately NOT validated here (inheriting StoreDnsRecordRequest's own
// already-documented scope cuts): a record's Name against any hostname-shaped
// pattern (Laravel's ValidDnsRecordName rule already enforces this), and a
// record Value's per-type internal structure (SRV's "weight port target",
// CAA's "flag tag value"). Value stays one opaque string end-to-end;
// named-checkconf -z is the authoritative backstop for anything that would
// corrupt zone-file syntax.
func ParsePayload(raw json.RawMessage) (Payload, error) {
	var p Payload

	dec := json.NewDecoder(bytes.NewReader(raw))
	dec.DisallowUnknownFields()
	if err := dec.Decode(&p); err != nil {
		return Payload{}, fmt.Errorf("decoding bind9 payload: %w", err)
	}

	if !hostnamePattern.MatchString(p.Domain) {
		return Payload{}, &ValidationError{Code: "invalid_domain", Message: "domain is not a valid hostname", Field: "domain"}
	}

	if p.TTL < minTTL || p.TTL > maxTTL {
		return Payload{}, &ValidationError{
			Code:    "ttl_out_of_range",
			Message: fmt.Sprintf("ttl must be between %d and %d seconds", minTTL, maxTTL),
			Field:   "ttl",
		}
	}

	for i, r := range p.Records {
		field := fmt.Sprintf("records[%d]", i)

		if r.Name == reservedRecordName {
			return Payload{}, &ValidationError{
				Code:    "reserved_record_name",
				Message: fmt.Sprintf("record name %q is reserved for the agent's own marker record", reservedRecordName),
				Field:   field + ".name",
			}
		}

		if !dnsRecordTypes[r.Type] {
			return Payload{}, &ValidationError{
				Code:    "unknown_record_type",
				Message: fmt.Sprintf("record type %q is not supported", r.Type),
				Field:   field + ".type",
			}
		}

		if requiresPriority(r.Type) && r.Priority == nil {
			return Payload{}, &ValidationError{
				Code:    "priority_required",
				Message: fmt.Sprintf("%s records require a priority", r.Type),
				Field:   field + ".priority",
			}
		}

		if !requiresPriority(r.Type) && r.Priority != nil {
			return Payload{}, &ValidationError{
				Code:    "priority_not_allowed",
				Message: fmt.Sprintf("%s records must not have a priority", r.Type),
				Field:   field + ".priority",
			}
		}
	}

	return p, nil
}
