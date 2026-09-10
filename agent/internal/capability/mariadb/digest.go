package mariadb

import (
	"context"
	"crypto/sha256"
	"encoding/hex"
	"fmt"
	"sort"
	"strconv"
	"strings"
)

// schemaExists queries live server state directly -- never local files, this
// capability has none representing "what's live" at all (the live state is
// entirely inside the tenant mariadbd process).
func schemaExists(ctx context.Context, cfg Config, databaseName string) (bool, error) {
	out, err := runSQL(ctx, cfg, fmt.Sprintf(
		"SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = '%s';\n",
		databaseName,
	))
	if err != nil {
		return false, err
	}

	return strings.TrimSpace(out) == "1", nil
}

func userExists(ctx context.Context, cfg Config, databaseUser string) (bool, error) {
	out, err := runSQL(ctx, cfg, fmt.Sprintf(
		"SELECT COUNT(*) FROM mysql.user WHERE User = '%s' AND Host = '%s';\n",
		databaseUser, grantHost,
	))
	if err != nil {
		return false, err
	}

	return strings.TrimSpace(out) == "1", nil
}

// grantsFor runs a real SHOW GRANTS FOR round-trip. Only ever called once
// userExists is already known true: SHOW GRANTS FOR a nonexistent user is
// itself a hard MariaDB error ("no such grant"), not an empty result set.
func grantsFor(ctx context.Context, cfg Config, databaseUser string) ([]string, error) {
	out, err := runSQL(ctx, cfg, fmt.Sprintf("SHOW GRANTS FOR '%s'@'%s';\n", databaseUser, grantHost))
	if err != nil {
		return nil, err
	}

	var lines []string

	for _, line := range strings.Split(out, "\n") {
		line = strings.TrimSpace(line)
		if line != "" {
			lines = append(lines, line)
		}
	}

	sort.Strings(lines)

	return lines, nil
}

// computeDigest fingerprints databaseName's live existence, databaseUser's
// (the tenant's own account) and statsUser's (the companion read-only
// statistics account) live existence and grants -- deliberately, disclosedly
// never either password. Comparing a password would require either
// persisting a comparable secret locally (a second, unencrypted copy of what
// the Laravel `password`/`stats_password` columns already keep encrypted at
// rest) or sending the plaintext on every observe (worse than the gap it
// would close): both strictly worse than the narrow, disclosed limitation of
// not detecting an out-of-band manual password change.
func computeDigest(ctx context.Context, cfg Config, databaseName, databaseUser, statsUser string) (string, error) {
	schemaLive, err := schemaExists(ctx, cfg, databaseName)
	if err != nil {
		return "", fmt.Errorf("checking schema existence: %w", err)
	}

	userLive, err := userExists(ctx, cfg, databaseUser)
	if err != nil {
		return "", fmt.Errorf("checking user existence: %w", err)
	}

	var grants []string

	if userLive {
		grants, err = grantsFor(ctx, cfg, databaseUser)
		if err != nil {
			return "", fmt.Errorf("reading grants: %w", err)
		}
	}

	statsUserLive, err := userExists(ctx, cfg, statsUser)
	if err != nil {
		return "", fmt.Errorf("checking stats user existence: %w", err)
	}

	var statsGrants []string

	if statsUserLive {
		statsGrants, err = grantsFor(ctx, cfg, statsUser)
		if err != nil {
			return "", fmt.Errorf("reading stats user grants: %w", err)
		}
	}

	var manifest strings.Builder

	fmt.Fprintf(&manifest, "schema_exists=%s\n", strconv.FormatBool(schemaLive))
	fmt.Fprintf(&manifest, "user_exists=%s\n", strconv.FormatBool(userLive))

	for _, g := range grants {
		fmt.Fprintf(&manifest, "grant: %s\n", g)
	}

	fmt.Fprintf(&manifest, "stats_user_exists=%s\n", strconv.FormatBool(statsUserLive))

	for _, g := range statsGrants {
		fmt.Fprintf(&manifest, "stats_grant: %s\n", g)
	}

	sum := sha256.Sum256([]byte(manifest.String()))

	return "sha256:" + hex.EncodeToString(sum[:]), nil
}
