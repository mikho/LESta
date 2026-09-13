package backup

import (
	"bytes"
	"context"
	"errors"
	"fmt"
	"os"
	"os/exec"
	"sort"
	"strings"
)

// databaseControlPlaneCapability and databaseTenantCapability are the two
// database capabilities this package captures via a real mysqldump/
// mariadb-dump, never a raw filesystem copy of their own live InnoDB
// datadir (see Config.DatabaseDumpSockets' own doc comment). Neither has a
// StateRoots entry alongside these constants for that same reason.
const (
	databaseControlPlaneCapability = "database.control-plane.v1"
	databaseTenantCapability       = "database.tenant.v1"
)

// mariadbDumpBinary resolves to the real dump binary on this node's PATH,
// preferring mariadb-dump (the real binary MariaDB Foundation's own
// mariadb-client package ships from 10.5 onward) and falling back to
// mysqldump (guaranteed present too, either as the same real binary on an
// older packaging or a compatibility symlink/wrapper on a newer one) rather
// than hardcoding one name and risking a node whose packaging only provides
// the other.
func mariadbDumpBinary() string {
	if path, err := exec.LookPath("mariadb-dump"); err == nil {
		return path
	}

	return "mysqldump"
}

// dumpDatabases runs a real mysqldump/mariadb-dump against every configured
// socket that actually has a live instance behind it on this node, returning
// each successful dump's own raw SQL bytes keyed by capability name, plus
// the sorted list of capabilities that were actually dumped (mirroring
// discoverIncludedCapabilities' own "included" convention for StateRoots).
// A configured socket whose file doesn't exist on disk is skipped, not an
// error: exactly like an empty StateRoots directory, it means that instance
// simply isn't running on this node.
func dumpDatabases(ctx context.Context, sockets map[string]string) (map[string][]byte, []string, error) {
	dumps := make(map[string][]byte, len(sockets))
	included := make([]string, 0, len(sockets))

	for _, capability := range []string{databaseControlPlaneCapability, databaseTenantCapability} {
		socket, ok := sockets[capability]
		if !ok {
			continue
		}

		if _, err := os.Stat(socket); errors.Is(err, os.ErrNotExist) {
			continue
		} else if err != nil {
			return nil, nil, fmt.Errorf("checking for %s's own socket at %s: %w", capability, socket, err)
		}

		dump, err := dumpSocket(ctx, socket)
		if err != nil {
			return nil, nil, fmt.Errorf("dumping %s: %w", capability, err)
		}

		dumps[capability] = dump
		included = append(included, capability)
	}

	sort.Strings(included)

	return dumps, included, nil
}

// discoverPresentDumpSockets reports which database capabilities currently
// have a live instance behind their configured socket, without actually
// running a dump: used only for an idempotent Create replay's own reported
// included_capabilities, where redoing a real mysqldump would defeat the
// entire point of skipping the already-done archive+encrypt work.
func discoverPresentDumpSockets(sockets map[string]string) []string {
	present := make([]string, 0, len(sockets))

	for _, capability := range []string{databaseControlPlaneCapability, databaseTenantCapability} {
		socket, ok := sockets[capability]
		if !ok {
			continue
		}

		if _, err := os.Stat(socket); err == nil {
			present = append(present, capability)
		}
	}

	sort.Strings(present)

	return present
}

// dumpSocket execs a real mysqldump/mariadb-dump against a live MariaDB
// instance over its own real unix socket, root-authenticated via
// unix_socket auth -- the same local, no-network-credential mechanism this
// project's own installer health checks already use (e.g. `mariadb
// --socket=... -u root`), so this never needs a tenant's own credentials
// transmitted anywhere. --all-databases captures every schema on the
// instance (including MariaDB's own system schemas, and, for
// database.control-plane.v1, Laravel's own control-plane schema), matching
// this capability's own existing "whole-node snapshot" scope rather than
// per-resource granularity (see Permission::CATALOG's own doc comment on
// why backups already commingle every account on a node). --single-
// transaction takes a real, consistent InnoDB snapshot via a single
// transaction rather than locking tables, which is precisely the safe
// mechanism Phase 31's own "no FLUSH TABLES WITH READ LOCK/mysqldump step"
// concern was waiting on; --quick streams rows rather than buffering the
// whole result set in mysqldump's own memory.
func dumpSocket(ctx context.Context, socketPath string) ([]byte, error) {
	cmd := exec.CommandContext(ctx, mariadbDumpBinary(),
		"--socket="+socketPath, "-u", "root",
		"--all-databases", "--single-transaction", "--quick")

	var stdout, stderr bytes.Buffer
	cmd.Stdout = &stdout
	cmd.Stderr = &stderr

	if err := cmd.Run(); err != nil {
		return nil, fmt.Errorf("%s: %w: %s", cmd.Path, err, strings.TrimSpace(stderr.String()))
	}

	return stdout.Bytes(), nil
}
