package mail

import (
	"os"
	"path/filepath"
	"reflect"
	"syscall"
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

func TestRemoveSieveData_MailboxAndDomain(t *testing.T) {
	root := t.TempDir()
	c := &MailCapability{cfg: Config{SieveDir: root}}
	domainDir := filepath.Join(root, "example.com")
	if err := os.MkdirAll(domainDir, 0o770); err != nil {
		t.Fatal(err)
	}
	for _, name := range []string{"alice.sieve", "alice.svbin", "bob.sieve", "bob.svbin"} {
		if err := os.WriteFile(filepath.Join(domainDir, name), []byte("x"), 0o644); err != nil {
			t.Fatal(err)
		}
	}

	if err := c.removeSieveData("example.com", "bob"); err != nil {
		t.Fatalf("removing a mailbox's script: %v", err)
	}
	entries, _ := os.ReadDir(domainDir)
	if len(entries) != 2 || entries[0].Name() != "alice.sieve" || entries[1].Name() != "alice.svbin" {
		t.Fatalf("want only alice's files left, got %v", entries)
	}
	if err := c.removeSieveData("example.com", "bob"); err != nil {
		t.Fatalf("an already removed script is not an error: %v", err)
	}

	if err := c.removeSieveData("example.com", ""); err != nil {
		t.Fatalf("removing the domain: %v", err)
	}
	if _, err := os.Stat(domainDir); !os.IsNotExist(err) {
		t.Fatal("the domain's Sieve folder is still there")
	}

	if err := c.removeSieveData("example.com", "../x"); err == nil {
		t.Fatal("an invalid local part should be refused")
	}
}

func TestEnsureSieveDomainDir_IsGroupWritableWithTheParentsGroup(t *testing.T) {
	parent := t.TempDir()
	dir := filepath.Join(parent, "example.com")
	if err := os.Mkdir(dir, 0o755); err != nil {
		t.Fatal(err)
	}

	if err := ensureSieveDomainDir(dir); err != nil {
		t.Fatalf("ensureSieveDomainDir: %v", err)
	}

	info, err := os.Stat(dir)
	if err != nil {
		t.Fatal(err)
	}
	parentInfo, err := os.Stat(parent)
	if err != nil {
		t.Fatal(err)
	}
	if info.Mode().Perm() != 0o770 || info.Mode()&os.ModeSetgid != 0 {
		t.Fatalf("want 0770 without setgid, got %v", info.Mode())
	}
	if info.Sys().(*syscall.Stat_t).Gid != parentInfo.Sys().(*syscall.Stat_t).Gid {
		t.Fatal("want the parent directory's group")
	}
}
