package backup

import (
	"context"
	"os"
	"path/filepath"
	"strings"
	"testing"
)

func TestAuthArgsLogsInAsRootOnlyWithoutAnOptionFile(t *testing.T) {
	if got := strings.Join(authArgs("", "-S", "/run/x.sock"), " "); got != "-S /run/x.sock -u root" {
		t.Errorf("without an option file: %q", got)
	}

	got := authArgs("/etc/lesta/backup.cnf", "-S", "/run/x.sock")
	if strings.Join(got, " ") != "--defaults-extra-file=/etc/lesta/backup.cnf -S /run/x.sock" {
		t.Errorf("with an option file: %q", got)
	}

	if strings.Contains(strings.Join(got, " "), "root") {
		t.Errorf("an option file must replace the root login")
	}
}

func TestAccountDumpsAndLoadsLogInAsTheConfiguredAccounts(t *testing.T) {
	dir := t.TempDir()
	record := filepath.Join(dir, "args")
	stub := filepath.Join(dir, "stub")

	// Records each call; answers the password hash query like the server would.
	script := "#!/bin/sh\necho \"$@\" >> " + record + "\ninput=$(cat)\ncase \"$input\" in *authentication_string*) echo '*0123456789ABCDEF0123456789ABCDEF01234567';; esac\n"
	if err := os.WriteFile(stub, []byte(script), 0o755); err != nil {
		t.Fatal(err)
	}

	cfg := Config{
		DumpBinary:            stub,
		ClientBinary:          stub,
		DatabaseDumpDefaults:  map[string]string{databaseTenantCapability: "/etc/lesta/backup.cnf"},
		TenantRestoreDefaults: "/etc/lesta/admin.cnf",
		TenantRestorePort:     3307,
	}

	if err := dumpOneDatabase(context.Background(), cfg, "/run/t.sock", "shop", filepath.Join(dir, "out.sql")); err != nil {
		t.Fatal(err)
	}

	if err := loadDatabase(context.Background(), cfg, "/run/t.sock", "lesta_1_shop", strings.NewReader("select 1;")); err != nil {
		t.Fatal(err)
	}

	raw, _ := os.ReadFile(record)
	lines := strings.Split(strings.TrimSpace(string(raw)), "\n")

	// dump, read the hash, add the temporary password, load, put the password back.
	if len(lines) != 5 || !strings.HasPrefix(lines[0], "--defaults-extra-file=/etc/lesta/backup.cnf -S /run/t.sock") || !strings.HasSuffix(lines[0], "--databases shop") {
		t.Fatalf("calls: %q", lines)
	}

	for _, i := range []int{1, 2, 4} {
		if !strings.HasPrefix(lines[i], "--defaults-extra-file=/etc/lesta/admin.cnf --protocol=tcp --host=127.0.0.1 --port=3307") {
			t.Errorf("administrator call %d: %q", i, lines[i])
		}
	}

	load := lines[3]
	if strings.Contains(load, "admin.cnf") || !strings.Contains(load, "--sandbox") || !strings.HasSuffix(load, "--database=lesta_1_shop") {
		t.Errorf("the load must run as the database's own user, sandboxed, on its own database: %q", load)
	}

	for _, line := range lines {
		if strings.Contains(line, "root") {
			t.Errorf("no call may log in as root: %q", line)
		}
	}
}

func TestLoadDatabaseRefusesANameThatIsNotATenantDatabase(t *testing.T) {
	for _, name := range []string{"mysql", "lesta_1_shop'; DROP", "lesta_2_other.secret", ""} {
		if err := loadDatabase(context.Background(), Config{ClientBinary: "/bin/false"}, "/run/t.sock", name, strings.NewReader("")); err == nil {
			t.Errorf("%q should be refused", name)
		}
	}
}

func TestWholeNodeRestoreLogsInAsTheRestoreAccountNotRoot(t *testing.T) {
	dir := t.TempDir()
	record := filepath.Join(dir, "args")

	if err := os.WriteFile(filepath.Join(dir, "mariadb"), []byte("#!/bin/sh\necho \"$@\" > "+record+"\ncat > /dev/null\n"), 0o755); err != nil {
		t.Fatal(err)
	}

	t.Setenv("PATH", dir+string(os.PathListSeparator)+os.Getenv("PATH"))

	if err := restoreSocket(context.Background(), "", "/run/t.sock", "/etc/lesta/restore.cnf", []byte("select 1;")); err != nil {
		t.Fatal(err)
	}

	raw, _ := os.ReadFile(record)
	if got := strings.TrimSpace(string(raw)); got != "--defaults-extra-file=/etc/lesta/restore.cnf --socket=/run/t.sock" {
		t.Errorf("restore arguments: %q", got)
	}

	if err := restoreSocket(context.Background(), "", "/run/t.sock", "", []byte("select 1;")); err != nil {
		t.Fatal(err)
	}

	raw, _ = os.ReadFile(record)
	if got := strings.TrimSpace(string(raw)); got != "--socket=/run/t.sock -u root" {
		t.Errorf("without a restore account the restore keeps the root login: %q", got)
	}
}

func TestAnAccountsBackupDirectoryGoesWithItsLastArchive(t *testing.T) {
	root := t.TempDir()
	capability := &BackupCapability{cfg: Config{ArtifactsRoot: root}}

	dir := filepath.Join(root, "accounts", "lesta-t4")
	if err := os.MkdirAll(dir, 0o700); err != nil {
		t.Fatal(err)
	}

	first := filepath.Join(dir, "a.acct.enc")
	second := filepath.Join(dir, "b.acct.enc")

	for _, f := range []string{first, second} {
		if err := os.WriteFile(f, []byte("x"), 0o600); err != nil {
			t.Fatal(err)
		}
	}

	_ = os.Remove(first)
	capability.removeEmptyAccountDir(first)

	if _, err := os.Stat(dir); err != nil {
		t.Fatalf("an account with another archive keeps its directory: %v", err)
	}

	_ = os.Remove(second)
	capability.removeEmptyAccountDir(second)

	if _, err := os.Stat(dir); !os.IsNotExist(err) {
		t.Fatalf("the emptied directory must go: %v", err)
	}

	if _, err := os.Stat(filepath.Join(root, "accounts")); err != nil {
		t.Fatalf("only the account's directory goes: %v", err)
	}
}
