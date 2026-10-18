package mail

import (
	"os"
	"path/filepath"
	"reflect"
	"testing"
)

func TestRemovedMailboxes_OnlyThoseTakenAway(t *testing.T) {
	previous := Payload{Accounts: []Account{{LocalPart: "alice"}, {LocalPart: "bob"}, {LocalPart: "carol", Suspended: true}}}
	current := Payload{Accounts: []Account{{LocalPart: "alice"}, {LocalPart: "carol", Suspended: true}, {LocalPart: "dave"}}}

	if got := removedMailboxes(previous, current); !reflect.DeepEqual(got, []string{"bob"}) {
		t.Fatalf("removedMailboxes = %v, want [bob]", got)
	}
	if got := removedMailboxes(Payload{}, current); got != nil {
		t.Fatalf("a new domain removes nothing, got %v", got)
	}
}

func TestRemoveMaildir_MailboxAndDomain(t *testing.T) {
	root := t.TempDir()
	c := &MailCapability{cfg: Config{VmailRoot: root}}

	for _, box := range []string{"alice", "bob"} {
		if err := os.MkdirAll(filepath.Join(root, "example.com", box, "Maildir", "cur"), 0o700); err != nil {
			t.Fatal(err)
		}
	}

	if err := c.removeMaildir("example.com", "bob"); err != nil {
		t.Fatalf("removing a mailbox: %v", err)
	}
	if _, err := os.Stat(filepath.Join(root, "example.com", "bob")); !os.IsNotExist(err) {
		t.Fatal("bob's mail is still there")
	}
	if _, err := os.Stat(filepath.Join(root, "example.com", "alice")); err != nil {
		t.Fatalf("alice's mail was touched: %v", err)
	}

	if err := c.removeMaildir("example.com", ""); err != nil {
		t.Fatalf("removing the domain: %v", err)
	}
	if _, err := os.Stat(filepath.Join(root, "example.com")); !os.IsNotExist(err) {
		t.Fatal("the domain's mail is still there")
	}

	for _, input := range [][2]string{{"../etc", ""}, {"example.com", "../x"}, {"", ""}} {
		if err := c.removeMaildir(input[0], input[1]); err == nil {
			t.Fatalf("removeMaildir(%q, %q) should refuse", input[0], input[1])
		}
	}

	if err := (&MailCapability{}).removeMaildir("example.com", "alice"); err != nil {
		t.Fatalf("without a VmailRoot nothing is removed and nothing fails: %v", err)
	}
}

func TestEnsureSieveDomainDir_IsGroupWritableAndSetgid(t *testing.T) {
	dir := filepath.Join(t.TempDir(), "example.com")

	if err := ensureSieveDomainDir(dir); err != nil {
		t.Fatalf("ensureSieveDomainDir: %v", err)
	}

	info, err := os.Stat(dir)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o770 || info.Mode()&os.ModeSetgid == 0 {
		t.Fatalf("want 0770 with setgid, got %v", info.Mode())
	}
}
