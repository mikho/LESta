package backup_test

import (
	"context"
	"net"
	"os"
	"os/exec"
	"path/filepath"
	"strconv"
	"strings"
	"testing"

	"github.com/mikho/LESta/agent/internal/capability/backup"
)

// TestAccountDatabaseRestoreRunsAsTheDatabaseOwnUserSandboxed proves the restore of an account
// backup's database against a real MariaDB: a real dump with a trigger defined by the tenant's
// user comes back, while SQL reaching another database, granting privileges or running a shell
// command (all possible in an imported, customer-made backup) is refused, and the user keeps its
// own password afterwards.
func TestAccountDatabaseRestoreRunsAsTheDatabaseOwnUserSandboxed(t *testing.T) {
	requireRealMariaDBForDump(t)

	listener, err := net.Listen("tcp", "127.0.0.1:0")
	if err != nil {
		t.Fatal(err)
	}
	port := listener.Addr().(*net.TCPAddr).Port
	_ = listener.Close()

	d := startDisposableDumpMariaDB(t, port)
	root := func(t *testing.T, script string) string {
		t.Helper()
		cmd := exec.Command("mariadb", "--socket="+d.socket, "-u", "root", "--batch", "--skip-column-names")
		cmd.Stdin = strings.NewReader(script)
		out, err := cmd.CombinedOutput()
		if err != nil {
			t.Fatalf("%v: %s", err, out)
		}
		return strings.TrimSpace(string(out))
	}
	asTenant := func(sql string) (string, error) {
		out, err := exec.Command("mariadb", "--protocol=tcp", "--host=127.0.0.1", "--port="+strconv.Itoa(port),
			"-u", "lesta_1_shop", "--password=original-password-0123", "--batch", "--skip-column-names",
			"lesta_1_shop", "-e", sql).CombinedOutput()
		return strings.TrimSpace(string(out)), err
	}

	root(t, "CREATE DATABASE lesta_1_shop; CREATE DATABASE lesta_2_other;"+
		"CREATE TABLE lesta_2_other.secret (v TEXT); INSERT INTO lesta_2_other.secret VALUES ('other tenant');"+
		"CREATE USER 'lesta_1_shop'@'127.0.0.1' IDENTIFIED BY 'original-password-0123';"+
		"GRANT ALL PRIVILEGES ON lesta_1_shop.* TO 'lesta_1_shop'@'127.0.0.1'; FLUSH PRIVILEGES;")
	if _, err := asTenant("CREATE TABLE orders (id INT); CREATE TABLE audit (id INT);" +
		"CREATE TRIGGER orders_audit AFTER INSERT ON orders FOR EACH ROW INSERT INTO audit VALUES (NEW.id);" +
		"INSERT INTO orders VALUES (1);"); err != nil {
		t.Fatal(err)
	}
	hashBefore := root(t, "SELECT authentication_string FROM mysql.user WHERE User = 'lesta_1_shop';")

	dumpPath := filepath.Join(t.TempDir(), "shop.sql")
	dump, err := exec.Command("mariadb-dump", "--socket="+d.socket, "-u", "root", "--single-transaction", "--routines", "--triggers", "--events", "--databases", "lesta_1_shop").Output()
	if err != nil {
		t.Fatal(err)
	}
	if err := os.WriteFile(dumpPath, dump, 0o600); err != nil {
		t.Fatal(err)
	}

	cfg := backup.Config{TenantRestorePort: port}
	load := func(sql string) error {
		return backup.LoadDatabaseForTest(context.Background(), cfg, d.socket, "lesta_1_shop", strings.NewReader(sql))
	}

	t.Run("a real dump with a trigger defined by the tenant's user comes back", func(t *testing.T) {
		if _, err := asTenant("DROP TRIGGER orders_audit; DROP TABLE orders; DROP TABLE audit;"); err != nil {
			t.Fatal(err)
		}
		if err := load(string(dump)); err != nil {
			t.Fatal(err)
		}
		if out, err := asTenant("INSERT INTO orders VALUES (2); SELECT COUNT(*) FROM audit;"); err != nil || out != "2" {
			t.Fatalf("the restored table and trigger should work: %q %v", out, err)
		}
	})

	t.Run("SQL reaching another tenant's database is refused", func(t *testing.T) {
		if err := load("SELECT * FROM lesta_2_other.secret;"); err == nil {
			t.Fatal("reading another database should fail")
		}
		if err := load("DROP DATABASE lesta_2_other;"); err == nil {
			t.Fatal("dropping another database should fail")
		}
		if root(t, "SELECT COUNT(*) FROM lesta_2_other.secret;") != "1" {
			t.Fatal("the other tenant's data must be untouched")
		}
	})

	t.Run("SQL granting privileges or creating users is refused", func(t *testing.T) {
		if err := load("CREATE USER 'intruder'@'%' IDENTIFIED BY 'x'; GRANT ALL ON *.* TO 'intruder'@'%';"); err == nil {
			t.Fatal("creating a user should fail")
		}
		if root(t, "SELECT COUNT(*) FROM mysql.user WHERE User = 'intruder';") != "0" {
			t.Fatal("no user may be created")
		}
	})

	t.Run("a shell command in the dump is not run", func(t *testing.T) {
		marker := filepath.Join(t.TempDir(), "ran")
		_ = load("\\! touch " + marker + "\nSELECT 1;")
		if _, err := os.Stat(marker); err == nil {
			t.Fatal("the client ran a shell command from the dump")
		}
	})

	t.Run("the user keeps its own password and nothing else afterwards", func(t *testing.T) {
		if after := root(t, "SELECT authentication_string FROM mysql.user WHERE User = 'lesta_1_shop';"); after != hashBefore {
			t.Fatalf("the password hash changed: %q -> %q", hashBefore, after)
		}
		if plugin := root(t, "SELECT JSON_VALUE(Priv, '$.auth_or') IS NULL FROM mysql.global_priv WHERE User = 'lesta_1_shop';"); plugin != "1" {
			t.Fatalf("the temporary second password should be gone: %q", plugin)
		}
		if _, err := asTenant("SELECT 1;"); err != nil {
			t.Fatalf("the original password should still work: %v", err)
		}
	})
}
