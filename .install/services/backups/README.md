# Backups

Real, shipped backup capability for whole-node, encrypted snapshots. Artifacts are pure Go (`archive/tar` + `compress/gzip`, encrypted with AES-GCM) written to **local disk only**, at `/var/lib/lesta/backups` -- there is no object storage integration; that was an earlier scoping idea this project deliberately did not build. Database content (`database.control-plane.v1`/`database.tenant.v1`) is captured via a real `mysqldump`/`mariadb-dump` against each instance's own live socket, never a raw filesystem copy of a running InnoDB directory.

Backups declares what it can back up through `backs_up`, not `depends_on`. It never requires the control-plane database, tenant databases, the web profile, DNS, or cron to exist before it installs; it only requires the base layout and node health (`base.layout.v1`, `node.health.v1`). At backup time it includes whichever `backs_up` capabilities are actually present and healthy on the node and silently omits the rest, recording exactly what was included in the run's result.

There is no manual prerequisite (no include-line, no config file this installer needs an operator to prepare first) -- unlike nginx/bind9/apache/mail, this capability never writes into another service's own config tree. It only reads other capabilities' own state roots to decide what to archive.

## `install.sh` scope

This service's manifest declares `packages: []` and `ports: []`: there is no external package this installer apt-get installs, no daemon to enable or restart. `install.sh` shrinks to exactly one real step: ensuring the owned artifacts directory (`/var/lib/lesta/backups`, mode 0750, `root:lesta`) exists with the right ownership.

```
install.sh --dry-run|--apply|--version [--yes] [--help]
```

- `--dry-run` — Run preflight and report what would change. No mutation.
- `--apply` — Apply the installer. Requires `--yes`.
- `--version` — Print installer version and exit.
- `--yes` — Required with `--apply`: non-interactive confirmation.

There is no `--offline-bundle` flag: this installer has no package to fetch.

## What health-checking actually proves

`bootstrap_node_health`'s self-test creates a real, disposable encrypted backup artifact against the just-installed capability, then deletes it, confirming both the create and delete paths return `applied` and that the artifact is really written to, and removed from, `/var/lib/lesta/backups`. Remote control-plane registration is a separate, later step -- the capability is structurally installed and health-checked by this installer, not yet control-plane-registered by it.
