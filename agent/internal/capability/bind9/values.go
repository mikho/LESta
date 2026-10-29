package bind9

import (
	"fmt"
	"net/netip"
	"regexp"
	"strconv"
	"strings"
)

// Record names and values become zone file text, which has its own syntax: a newline starts a
// new line, a leading "$" a directive ($INCLUDE reads a file as root, $GENERATE creates any number
// of records), ";" a comment, and "(" and ")" join lines. So every name and value is checked
// against its type's own grammar here, again after the panel, and nothing outside it is rendered.
// TXT values are the only free text, and they are quoted and escaped when rendered; they still
// may not contain control characters.

// recordNamePattern mirrors App\Rules\ValidDnsRecordName: "@", "*", or labels of letters, digits,
// "_" and inner "-".
var recordNamePattern = regexp.MustCompile(`^(?:@|\*|[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?(?:\.[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?)*)$`)

// targetPattern is a host name a record points at, with or without its trailing dot.
var targetPattern = regexp.MustCompile(`^[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?(?:\.[A-Za-z0-9_](?:[A-Za-z0-9_-]{0,61}[A-Za-z0-9_])?)*\.?$`)

// srvPattern is an SRV value without its priority: "weight port target", the target "." for "no
// such service".
var srvPattern = regexp.MustCompile(`^([0-9]{1,5}) ([0-9]{1,5}) (\.|[A-Za-z0-9_][A-Za-z0-9_.-]*)$`)

// caaPattern is a CAA value: "flags tag "value"", the value free of quotes, backslashes and control
// characters.
var caaPattern = regexp.MustCompile(`^([0-9]{1,3}) (issue|issuewild|iodef|issuemail|issuevmc) "([^"\\\x00-\x1f\x7f]{0,255})"$`)

const (
	maxRecordNameLength = 191
	maxTargetLength     = 253
	maxTXTLength        = 2048
)

// validateRecordValue checks one record's name and value against its type's grammar.
func validateRecordValue(r Record, field string) *ValidationError {
	if len(r.Name) > maxRecordNameLength || !recordNamePattern.MatchString(r.Name) {
		return &ValidationError{Code: "invalid_record_name", Message: "a record name is @, * or a host name of letters, digits, _ and -", Field: field + ".name"}
	}

	invalid := func(message string) *ValidationError {
		return &ValidationError{Code: "invalid_record_value", Message: fmt.Sprintf("%s record: %s", r.Type, message), Field: field + ".value"}
	}

	switch r.Type {
	case "A", "AAAA":
		addr, err := netip.ParseAddr(r.Value)
		if err != nil || addr.Zone() != "" || (r.Type == "A") != addr.Is4() {
			return invalid("the value must be a single " + map[string]string{"A": "IPv4", "AAAA": "IPv6"}[r.Type] + " address")
		}
	case "NS", "CNAME", "PTR", "MX":
		if len(r.Value) > maxTargetLength || !targetPattern.MatchString(r.Value) {
			return invalid("the value must be a host name")
		}
	case "SRV":
		m := srvPattern.FindStringSubmatch(r.Value)
		if m == nil || len(m[3]) > maxTargetLength || (m[3] != "." && !targetPattern.MatchString(m[3])) || !fitsUint16(m[1]) || !fitsUint16(m[2]) {
			return invalid(`the value must be "weight port target", for example "5 5060 sip.example.com."`)
		}
	case "CAA":
		m := caaPattern.FindStringSubmatch(r.Value)
		if m == nil {
			return invalid(`the value must be "flags tag \"value\"", for example "0 issue \"letsencrypt.org\""`)
		}
		if flags, _ := strconv.Atoi(m[1]); flags > 255 {
			return invalid("flags must be between 0 and 255")
		}
	case "TXT":
		if len(r.Value) > maxTXTLength || strings.IndexFunc(r.Value, isControl) >= 0 {
			return invalid(fmt.Sprintf("the value may be at most %d characters and may not contain line breaks or other control characters", maxTXTLength))
		}
	}

	return nil
}

func fitsUint16(digits string) bool {
	n, err := strconv.Atoi(digits)

	return err == nil && n <= 65535
}

func isControl(r rune) bool {
	return r < 0x20 || r == 0x7f
}
