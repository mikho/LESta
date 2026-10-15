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

	if err := os.WriteFile(stub, []byte("#!/bin/sh\necho \"$@\" >> "+record+"\n"), 0o755); err != nil {
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

	if err := loadDatabase(context.Background(), cfg, "/run/t.sock", strings.NewReader("select 1;")); err != nil {
		t.Fatal(err)
	}

	raw, _ := os.ReadFile(record)
	lines := strings.Split(strings.TrimSpace(string(raw)), "\n")

	if len(lines) != 2 || !strings.HasPrefix(lines[0], "--defaults-extra-file=/etc/lesta/backup.cnf -S /run/t.sock") || !strings.HasSuffix(lines[0], "--databases shop") {
		t.Errorf("dump arguments: %q", lines)
	}

	if lines[1] != "--defaults-extra-file=/etc/lesta/admin.cnf --protocol=tcp --host=127.0.0.1 --port=3307" {
		t.Errorf("load arguments: %q", lines[1])
	}

	for _, line := range lines {
		if strings.Contains(line, "root") {
			t.Errorf("no call may log in as root: %q", line)
		}
	}
}
