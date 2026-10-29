package bind9

import (
	"strings"
	"testing"
)

func TestValidateRecordValueRefusesZoneFileInjection(t *testing.T) {
	cases := []struct {
		name   string
		record Record
	}{
		{"an $INCLUDE line after an SRV value", Record{Name: "_sip._tcp", Type: "SRV", Value: "0 0 x.\n$INCLUDE /etc/shadow"}},
		{"a $GENERATE line after a CAA value", Record{Name: "@", Type: "CAA", Value: "0 issue \"x\"\n$GENERATE 1-2000000000 a$ A 1.2.3.4"}},
		{"a CAA value with a comment", Record{Name: "@", Type: "CAA", Value: "0 issue \"x\" ; comment"}},
		{"a TXT value with a line break", Record{Name: "@", Type: "TXT", Value: "v=spf1\n$TTL 1"}},
		{"an A value with a second word", Record{Name: "www", Type: "A", Value: "203.0.113.5 ; x"}},
		{"an A value that is IPv6", Record{Name: "www", Type: "A", Value: "2001:db8::1"}},
		{"an AAAA value that is IPv4", Record{Name: "www", Type: "AAAA", Value: "203.0.113.5"}},
		{"a CNAME target with a space", Record{Name: "www", Type: "CNAME", Value: "example.com. IN A 1.2.3.4"}},
		{"a name that is a directive", Record{Name: "$ORIGIN", Type: "A", Value: "203.0.113.5"}},
		{"a name with a line break", Record{Name: "www\n@", Type: "A", Value: "203.0.113.5"}},
		{"an SRV port out of range", Record{Name: "_sip._tcp", Type: "SRV", Value: "5 70000 sip.example.com."}},
		{"a CAA flag out of range", Record{Name: "@", Type: "CAA", Value: "300 issue \"letsencrypt.org\""}},
		{"a CAA unknown tag", Record{Name: "@", Type: "CAA", Value: "0 evil \"x\""}},
		{"a TXT value too long", Record{Name: "@", Type: "TXT", Value: strings.Repeat("a", 2049)}},
	}

	for _, tc := range cases {
		t.Run(tc.name, func(t *testing.T) {
			if verr := validateRecordValue(tc.record, "records[0]"); verr == nil {
				t.Fatalf("%q should be refused", tc.record.Value)
			}
		})
	}
}

func TestValidateRecordValueAcceptsOrdinaryRecords(t *testing.T) {
	for _, r := range []Record{
		{Name: "@", Type: "A", Value: "203.0.113.5"},
		{Name: "www", Type: "AAAA", Value: "2001:db8::5"},
		{Name: "v6", Type: "AAAA", Value: "2001:DB8:0::5"},
		{Name: "*", Type: "CNAME", Value: "example.com."},
		{Name: "mail", Type: "MX", Value: "mail.example.com"},
		{Name: "sub", Type: "NS", Value: "ns1.other.example."},
		{Name: "_sip._tcp", Type: "SRV", Value: "5 5060 sip.example.com."},
		{Name: "_none._tcp", Type: "SRV", Value: "0 0 ."},
		{Name: "@", Type: "CAA", Value: "0 issue \"letsencrypt.org\""},
		{Name: "@", Type: "CAA", Value: "128 iodef \"mailto:security@example.com\""},
		{Name: "@", Type: "TXT", Value: "v=spf1 mx -all; \"quoted\" and \\ backslash"},
		{Name: "lesta1._domainkey", Type: "TXT", Value: "v=DKIM1; k=rsa; p=" + strings.Repeat("A", 400)},
		{Name: "1.113.0.203.in-addr.arpa", Type: "PTR", Value: "host.example.com."},
	} {
		if verr := validateRecordValue(r, "records[0]"); verr != nil {
			t.Errorf("%s %s %q should be accepted: %v", r.Name, r.Type, r.Value, verr)
		}
	}
}
