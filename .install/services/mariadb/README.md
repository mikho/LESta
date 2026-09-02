# MariaDB

MariaDB supports two isolated roles: the private control-plane store and the tenant database capability. They run as two separate MariaDB server instances, not one shared instance with logical separation. Both instances may live on the same physical node for the single-node deployment, control-plane on port 3306, tenant on port 3307, each with its own data directory, configuration fragment, credentials, and resource limits. They never share schemas, users, grants, sockets, or ports. MariaDB 11.4 LTS is the approved target for the first implementation; MySQL compatibility is not pursued.

## `install.sh` scope

`install.sh` only ever installs and manages the **tenant** instance (port 3307, `database.tenant.v1`). The control-plane instance (port 3306, `database.control-plane.v1`) is a separate, not-yet-written installer's responsibility; this script never creates, starts, stops, or registers it, and never touches the default (un-suffixed) `mariadb.service`.

It uses Ubuntu's own packaged `mariadb@.service` systemd template unit: `systemctl enable --now mariadb@tenant.service` passes `--defaults-group-suffix=.tenant`, so `mariadbd` additionally reads a `[mysqld.tenant]` option group from `/etc/mysql/mariadb.conf.d/99-lesta-tenant.cnf` (this service's own owned root) on top of every base `[mysqld]` group already in effect. That fragment sets `port`, `datadir`, `socket`, `pid-file`, `bind-address`, and `log-error` explicitly — every one of them, not just `port` — since the group-suffix mechanism only ever *adds* a more specific override; omitting any one risks the tenant instance silently sharing the control-plane instance's own socket or datadir.

## Known gap: MariaDB Foundation's apt repository and Ubuntu 26.04

MariaDB Foundation's own apt repository for MariaDB 11.4 LTS currently publishes package metadata for Ubuntu 24.04 (noble) and 22.04 (jammy) only — confirmed against MariaDB's own documentation, which does not list Ubuntu 26.04 (resolute) among its supported distributions. `install.sh` fails closed with a clear `mariadb_repo_unavailable` preflight error on Ubuntu 26.04 rather than silently falling back to whatever MariaDB version Ubuntu's own default archive ships there (which would violate the ADR's pinned-11.4-LTS requirement without saying so). If MariaDB Foundation later publishes a `resolute` suite for 11.4, or the ADR is amended to accept a different version on 26.04, `install.sh`'s own `preflight_check_mariadb_repo_available` is the one place that needs updating.
