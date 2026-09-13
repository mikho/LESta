package metrics

import (
	"bytes"
	"context"
	"fmt"
	"os"
	"os/exec"
	"strconv"
	"strings"
)

// databaseDiskBytes runs a real, read-only information_schema query against
// the tenant MariaDB instance, connecting with ref's own dedicated
// stats_user/stats_password -- never a shared admin credential, matching
// the Statistics design's own "never share credentials with tenant
// provisioning writes" constraint. The credential is written to a
// temporary, mode-0600, --defaults-extra-file (never passed via -e/argv,
// keeping it out of `ps`, mirroring internal/capability/mariadb's own
// stdin-piped-SQL discipline for the exact same reason) and removed
// immediately after this single query, regardless of outcome.
func databaseDiskBytes(ctx context.Context, cfg Config, ref TenantDatabaseRef) (int64, error) {
	credFile, err := os.CreateTemp("", "lesta-metrics-stats-*.cnf")
	if err != nil {
		return 0, fmt.Errorf("creating temporary credentials file: %w", err)
	}
	defer os.Remove(credFile.Name())

	if err := credFile.Chmod(0o600); err != nil {
		credFile.Close()

		return 0, fmt.Errorf("securing temporary credentials file: %w", err)
	}

	credContent := fmt.Sprintf("[client]\nuser=%s\npassword=%s\n", ref.StatsUser, ref.StatsPassword)
	if _, err := credFile.WriteString(credContent); err != nil {
		credFile.Close()

		return 0, fmt.Errorf("writing temporary credentials file: %w", err)
	}

	if err := credFile.Close(); err != nil {
		return 0, fmt.Errorf("closing temporary credentials file: %w", err)
	}

	query := fmt.Sprintf(
		"SELECT COALESCE(SUM(data_length + index_length), 0) FROM information_schema.tables WHERE table_schema = '%s';\n",
		ref.DatabaseName,
	)

	args := []string{
		"--defaults-extra-file=" + credFile.Name(),
		"--host=" + cfg.mariadbHost(),
		"--port=" + strconv.Itoa(cfg.MariaDBPort),
		"--protocol=TCP",
		"--batch",
		"--skip-column-names",
		"--disable-reconnect",
	}

	cmd := exec.CommandContext(ctx, cfg.mariadbBinary(), args...)
	cmd.Stdin = strings.NewReader(query)

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return 0, fmt.Errorf("%w: %s", err, strings.TrimSpace(stderr.String()))
	}

	size, err := strconv.ParseInt(strings.TrimSpace(stdout.String()), 10, 64)
	if err != nil {
		return 0, fmt.Errorf("parsing information_schema query result %q: %w", stdout.String(), err)
	}

	return size, nil
}
