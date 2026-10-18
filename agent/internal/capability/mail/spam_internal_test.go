package mail

import (
	"strings"
	"testing"
)

func TestRenderSieveScript_SpamFilingFollowsSensitivityAndDomainAntispam(t *testing.T) {
	script := renderSieveScript(Account{LocalPart: "info"}, true)
	for _, want := range []string{`"fileinto"`, `"comparator-i;ascii-numeric"`, `"X-Lesta-Spam-Score-Int" "50"`, `fileinto :create "Junk";`} {
		if !strings.Contains(script, want) {
			t.Fatalf("the default (medium) script lacks %s:\n%s", want, script)
		}
	}

	if got := renderSieveScript(Account{LocalPart: "info", SpamSensitivity: "high"}, true); !strings.Contains(got, `"30"`) {
		t.Fatalf("high should file from 3.0:\n%s", got)
	}
	if got := renderSieveScript(Account{LocalPart: "info", SpamSensitivity: "low"}, true); !strings.Contains(got, `"80"`) {
		t.Fatalf("low should file from 8.0:\n%s", got)
	}
	if got := renderSieveScript(Account{LocalPart: "info", SpamSensitivity: "off"}, true); got != "" {
		t.Fatalf("off with nothing else should need no script, got:\n%s", got)
	}
	if got := renderSieveScript(Account{LocalPart: "info"}, false); got != "" {
		t.Fatalf("without antispam on the domain there is no score to act on, got:\n%s", got)
	}
}

func TestRenderSieveScript_BlockAndAllowLists(t *testing.T) {
	forward := "boss@example.net"
	script := renderSieveScript(Account{
		LocalPart: "info",
		SpamBlock: []string{"pest@spam.example", "junkmail.example"},
		SpamAllow: []string{"friend@partner.example", "partner.example"},
		ForwardTo: &forward,
	}, true)

	block := strings.Index(script, `discard;`)
	junk := strings.Index(script, `fileinto :create "Junk";`)
	redirect := strings.Index(script, `redirect :copy "boss@example.net";`)

	if block < 0 || junk < 0 || redirect < 0 || !(block < junk && junk < redirect) {
		t.Fatalf("expected block, then spam filing, then forwarding:\n%s", script)
	}
	for _, want := range []string{
		`if address :is :all "from" ["pest@spam.example"]`,
		`if address :is :domain "from" ["junkmail.example"]`,
		`not address :is :all "from" ["friend@partner.example"]`,
		`not address :is :domain "from" ["partner.example"]`,
	} {
		if !strings.Contains(script, want) {
			t.Fatalf("missing %s:\n%s", want, script)
		}
	}

	// A block list works without antispam on the domain; it needs no score.
	if got := renderSieveScript(Account{LocalPart: "info", SpamBlock: []string{"spam.example"}}, false); !strings.Contains(got, "discard;") || strings.Contains(got, "require") {
		t.Fatalf("a block list alone should discard and require nothing:\n%s", got)
	}
}

func TestValidateSpamSettings_RefusesWhatCouldBreakTheScript(t *testing.T) {
	for _, bad := range [][]string{
		{`a"b@example.com`},
		{"evil@example.com\"]; redirect \"x@y.example\""},
		{"two words.example"},
		{"UPPER@Example.com"},
		{"x@y.example\nstop;"},
		{""},
	} {
		if err := validateSpamSettings(0, Account{SpamBlock: bad}); err == nil {
			t.Fatalf("block list %q should be refused", bad)
		}
		if err := validateSpamSettings(0, Account{SpamAllow: bad}); err == nil {
			t.Fatalf("allow list %q should be refused", bad)
		}
	}

	if err := validateSpamSettings(0, Account{SpamSensitivity: "extreme"}); err == nil {
		t.Fatal("an unknown sensitivity should be refused")
	}

	tooMany := make([]string, maxSpamListEntries+1)
	for i := range tooMany {
		tooMany[i] = "example.com"
	}
	if err := validateSpamSettings(0, Account{SpamBlock: tooMany}); err == nil {
		t.Fatal("more than the maximum entries should be refused")
	}

	if err := validateSpamSettings(0, Account{SpamSensitivity: "high", SpamAllow: []string{"friend@partner.example", "partner.example"}, SpamBlock: []string{"sub.spam.example"}}); err != nil {
		t.Fatalf("valid settings were refused: %v", err)
	}
}
