package mail

import "testing"

func TestPruneCredentialsDropsHashesOfMailboxesThatAreGone(t *testing.T) {
	c := &MailCapability{cfg: Config{StateRoot: t.TempDir()}}

	if err := c.saveCredentials(map[string]string{
		"alice@example.com": "{BLF-CRYPT}alice",
		"bob@example.com":   "{BLF-CRYPT}bob",
		"carol@gone.test":   "{BLF-CRYPT}carol",
	}); err != nil {
		t.Fatal(err)
	}

	if err := c.pruneCredentials([]Payload{{Domain: "example.com", Accounts: []Account{{LocalPart: "alice"}}}}); err != nil {
		t.Fatalf("pruneCredentials: %v", err)
	}

	hashes, err := c.loadCredentials()
	if err != nil {
		t.Fatal(err)
	}
	if len(hashes) != 1 || hashes["alice@example.com"] != "{BLF-CRYPT}alice" {
		t.Fatalf("archive after pruning = %v, want only alice's hash", hashes)
	}
}
