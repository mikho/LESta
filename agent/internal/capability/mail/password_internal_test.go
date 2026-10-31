package mail

import (
	"context"
	"os/exec"
	"strings"
	"testing"
)

func TestHashPasswordProducesAHashDoveadmVerifies(t *testing.T) {
	if _, err := exec.LookPath("doveadm"); err != nil {
		t.Skip("doveadm not on PATH")
	}

	c := &MailCapability{}
	plaintext := strings.Repeat("ab12", 12)

	hash, err := c.hashPassword(context.Background(), plaintext)
	if err != nil {
		t.Fatalf("hashPassword: %v", err)
	}
	if !strings.HasPrefix(hash, "{BLF-CRYPT}") {
		t.Fatalf("hash = %q, want a BLF-CRYPT hash", hash)
	}

	if out, err := exec.Command("doveadm", "pw", "-t", hash, "-p", plaintext).CombinedOutput(); err != nil {
		t.Fatalf("doveadm does not verify the hash against the password: %v: %s", err, out)
	}
	if out, err := exec.Command("doveadm", "pw", "-t", hash, "-p", "").CombinedOutput(); err == nil {
		t.Fatalf("the hash also verifies against an empty password: %s", out)
	}
}

func TestHashPasswordRefusesALineBreak(t *testing.T) {
	c := &MailCapability{}

	if _, err := c.hashPassword(context.Background(), "abc\nabc"); err == nil {
		t.Fatal("hashPassword accepted a password with a line break")
	}
}
