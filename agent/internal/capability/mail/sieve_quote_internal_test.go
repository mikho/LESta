package mail

import (
	"os"
	"os/exec"
	"path/filepath"
	"strings"
	"testing"
)

func TestSieveQuoteKeepsLineBreaksAndEscapesOnlyQuoteAndBackslash(t *testing.T) {
	got := sieveQuote("Hej!\r\nÅter \"måndag\" C:\\tmp\x00\x07\tslut")
	want := "\"Hej!\nÅter \\\"måndag\\\" C:\\\\tmp\tslut\""

	if got != want {
		t.Fatalf("sieveQuote = %q, want %q", got, want)
	}
}

func TestAnAutoreplyWithLineBreaksAndQuotesCompiles(t *testing.T) {
	sievec, err := exec.LookPath("sievec")
	if err != nil {
		t.Skip("sievec not on PATH")
	}

	message := "Hej,\nI'm away until \"Monday\".\nBackslash: \\ done."
	script := renderSieveScript(Account{AutoreplyEnabled: true, AutoreplyMessage: &message}, false)

	if !strings.Contains(script, "\nI'm away until \\\"Monday\\\".\n") {
		t.Fatalf("the line breaks did not survive rendering:\n%s", script)
	}

	path := filepath.Join(t.TempDir(), "autoreply.sieve")
	if err := os.WriteFile(path, []byte(script), 0o600); err != nil {
		t.Fatal(err)
	}

	if out, err := exec.Command(sievec, path).CombinedOutput(); err != nil {
		t.Fatalf("sievec rejected the script: %v: %s\n%s", err, out, script)
	}
}
