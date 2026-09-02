package mariadb

import (
	"bytes"
	"context"
	"fmt"
	"os/exec"
	"strings"
)

// runSQL executes script (one or more semicolon-terminated SQL statements)
// against the tenant MariaDB instance via a real mariadb CLI invocation,
// piped over stdin -- never via -e, keeping SQL text (including, for
// create/rotate, the password itself) out of `ps`. Returns stdout (tab-
// separated, header-free rows, per Config.baseArgs' own --batch
// --skip-column-names) on success.
//
// A non-nil error here always means the invocation itself failed (bad
// credentials, connection refused, a rejected DDL statement, a syntax
// error): a real, well-formed operational failure, not a "no verdict
// reached" condition. Every caller in this package turns it into
// protocol.StatusFailed via failed(), never lets it escape as a bare Go
// error.
func runSQL(ctx context.Context, cfg Config, script string) (string, error) {
	cmd := exec.CommandContext(ctx, cfg.mariadbBinary(), cfg.baseArgs()...)
	cmd.Stdin = strings.NewReader(script)

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return "", fmt.Errorf("%w: %s", err, strings.TrimSpace(stderr.String()))
	}

	return stdout.String(), nil
}

// grantHost is the literal host every GRANT/REVOKE/CREATE USER/ALTER USER/
// DROP USER statement in this package targets. Always '127.0.0.1', never
// 'localhost': MySQL/MariaDB specifically special-case the bare hostname
// "localhost" to mean "this connection arrived over a Unix domain socket",
// which never matches a real TCP connection from a hosting customer's own
// application, even one running on the very same host connecting to
// 127.0.0.1 -- so a grant written against 'localhost' would silently never
// apply to any real tenant connection at all.
const grantHost = "127.0.0.1"

// createDDL: CREATE DATABASE IF NOT EXISTS, CREATE OR REPLACE USER
// IDENTIFIED BY, GRANT ALL PRIVILEGES, FLUSH PRIVILEGES. databaseName,
// databaseUser, and password are all pre-validated by ParsePayload against
// fixed charsets that can never contain a quote or backslash (identifierPattern,
// passwordPattern), so direct interpolation here needs no SQL-string-escaping
// logic at all -- that is the actual guarantee, not a convenience.
func createDDL(databaseName, databaseUser, password string) string {
	return fmt.Sprintf(
		"CREATE DATABASE IF NOT EXISTS `%s`;\n"+
			"CREATE OR REPLACE USER '%s'@'%s' IDENTIFIED BY '%s';\n"+
			"GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'%s';\n"+
			"FLUSH PRIVILEGES;\n",
		databaseName,
		databaseUser, grantHost, password,
		databaseName, databaseUser, grantHost,
	)
}

// rotateDDL: ALTER USER ... IDENTIFIED BY, never CREATE OR REPLACE USER.
// This is the single most important correctness detail in this whole
// capability. CREATE OR REPLACE USER is documented by MariaDB as drop-then-
// recreate: reusing it here for a password rotation would silently wipe
// every grant the create DDL above (or a later suspend/unsuspend) already
// established, either stripping an active tenant's access outright or --
// worse -- silently re-activating a currently-suspended one, since the
// freshly recreated user would carry no revoked state at all. ALTER USER
// changes only the credential and leaves every existing grant exactly as it
// was, composing correctly regardless of whether this database happens to
// be suspended or active at rotation time.
func rotateDDL(databaseUser, password string) string {
	return fmt.Sprintf(
		"ALTER USER '%s'@'%s' IDENTIFIED BY '%s';\n"+
			"FLUSH PRIVILEGES;\n",
		databaseUser, grantHost, password,
	)
}

// suspendDDL: REVOKE ALL PRIVILEGES, GRANT OPTION FROM, FLUSH PRIVILEGES.
// Idempotent: revoking from a user that already has nothing left to revoke
// does not error, since the user row itself is never dropped by this
// statement.
func suspendDDL(databaseUser string) string {
	return fmt.Sprintf(
		"REVOKE ALL PRIVILEGES, GRANT OPTION FROM '%s'@'%s';\n"+
			"FLUSH PRIVILEGES;\n",
		databaseUser, grantHost,
	)
}

// unsuspendDDL: re-GRANT, no IDENTIFIED BY at all -- restores access without
// touching the credential.
func unsuspendDDL(databaseName, databaseUser string) string {
	return fmt.Sprintf(
		"GRANT ALL PRIVILEGES ON `%s`.* TO '%s'@'%s';\n"+
			"FLUSH PRIVILEGES;\n",
		databaseName, databaseUser, grantHost,
	)
}

// deleteDDL: DROP DATABASE IF EXISTS, DROP USER IF EXISTS, FLUSH PRIVILEGES.
func deleteDDL(databaseName, databaseUser string) string {
	return fmt.Sprintf(
		"DROP DATABASE IF EXISTS `%s`;\n"+
			"DROP USER IF EXISTS '%s'@'%s';\n"+
			"FLUSH PRIVILEGES;\n",
		databaseName,
		databaseUser, grantHost,
	)
}
