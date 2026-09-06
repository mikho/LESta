#!/bin/sh
# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/services/mariadb/install.sh
#
# Bootstrap installer for BOTH MariaDB-backed capabilities this node's
# manifest.json declares: database.control-plane.v1 (port 3306, this
# application's own private database) and database.tenant.v1 (port 3307,
# the hosted-tenant database feature). Takes a bare Ubuntu 24.04 node (or one
# that already has any other leaf-service capability bootstrapped) to a state
# where both instances are structurally installed and health-checked, and
# where the Go agent's own mariadbProductionConfig() preconditions
# (agent/cmd/lesta-agent/main.go) are met for the tenant instance, per
# .install/INSTALLER-CONTRACT.md.
#
# The two instances are fully isolated per this service's own README.md (own
# datadir, own config fragment, own credentials, never sharing schemas,
# users, grants, sockets, or ports), but they are mechanically very
# different:
#
# - The TENANT instance (port 3307) uses Ubuntu's packaged `mariadb@.service`
#   systemd TEMPLATE unit with `--defaults-group-suffix=.tenant`, reading a
#   `[mysqld.tenant]` option group. Its datadir is created empty; the
#   packaged unit's own ExecStartPre runs mariadb-install-db automatically
#   the first time this specific instance name is ever started, so this
#   installer never runs mariadb-install-db itself for the tenant instance.
#
# - The CONTROL-PLANE instance (port 3306) uses Ubuntu's plain DEFAULT
#   `mariadb.service` (no group suffix at all), reading a plain `[mysqld]`
#   group -- it is the only instance on this node using the default,
#   un-suffixed group. By the time this installer's own control-plane phase
#   runs, mariadb-server's package postinst has almost certainly ALREADY
#   auto-started `mariadb.service` against the stock `/var/lib/mysql`
#   datadir and already run mariadb-install-db there (creating the
#   `debian-sys-maint` account tracked by /etc/mysql/debian.cnf, a refused
#   root per this service's own manifest.json -- never touched). Bootstrapping
#   control-plane is therefore a RELOCATION of an already-live,
#   already-initialized instance onto this project's own managed datadir
#   path, not a "create an empty directory and let the unit populate it"
#   flow -- structurally different from the tenant phase, and handled by its
#   own install_mariadb_control_plane function below rather than by copying
#   the tenant phase's pattern.
#
# Mirrors bind9/install.sh's own overall structure closely (the same overall
# phase shape: bootstrap_base, bootstrap_firewall_baseline, one or more
# install_mariadb_* phases, bootstrap_node_health), sharing the
# capability-agnostic plumbing every installer in this family needs via
# lib/result.sh, lib/firewall.sh, lib/agent.sh, and lib/selftest.sh.
# bootstrap_node_health's own self-test remains tenant-only by design:
# database.control-plane.v1 has no Go agent dispatch at all (it is this
# application's own database, not a capability the agent manages on a
# tenant's behalf), so its own health check is the real `SELECT 1` probe in
# install_mariadb_control_plane, nothing more.
#
# **Confirmed mechanism, not a guess**: Ubuntu's mariadb-server package ships
# a real, packaged `mariadb@.service` systemd template unit (confirmed
# against the real Ubuntu package filelist and MariaDB's own documentation of
# this exact mechanism: https://mariadb.com/docs/server/server-management/
# starting-and-stopping-mariadb/systemd/starting -- "a systemd template unit
# file called mariadb@.service ... uses .%I as the custom option group
# suffix ... appended to any server option group in any configuration file
# included by default"). `systemctl enable --now mariadb@tenant.service`
# passes `--defaults-group-suffix=.tenant`, making mariadbd additionally read
# a `[mysqld.tenant]` option group from
# /etc/mysql/mariadb.conf.d/99-lesta-tenant.cnf -- exactly the owned root
# this service's own manifest.json already declares. **Critical hazard**:
# the tenant instance still reads every base mariadb.conf.d/*.cnf file's
# plain `[mysqld]` group too (the group-suffix mechanism only ever ADDS a
# more-specific override on top), so `99-lesta-tenant.cnf`'s own
# `[mysqld.tenant]` group must explicitly set every instance-distinguishing
# key (port, datadir, socket, pid-file, bind-address, log-error) below or
# risk silently sharing the control-plane instance's own socket/datadir/port.
# The packaged unit's own ExecStartPre runs mariadb-install-db automatically
# (confirmed by the same MariaDB documentation page above: "Because
# mariadb-install-db reads the same sections as the server, and
# ExecStartPre=run mariadb-install-db within the service, the instances are
# automatically created if there are sufficient privileges"), so this
# installer never manually initializes the datadir itself -- it only ensures
# the directory exists, empty, and correctly owned (mysql:mysql) first.
#
# **Disclosed, real, verified gap (not a guess)**: MariaDB Foundation's own
# apt repository for MariaDB 11.4 LTS currently publishes package metadata
# for Ubuntu 24.04 (noble) and Ubuntu 22.04 (jammy) only -- confirmed
# directly against MariaDB's own current documentation
# (installing-mariadb-deb-files.md's own "We currently have APT repositories
# for the following Linux distributions" list, which does not include
# Ubuntu 26.04/resolute at all) and independently corroborated by multiple
# current third-party install guides describing the exact same limitation
# for Ubuntu 26.04 (Resolute Raccoon). Rather than silently falling back to
# whatever MariaDB version Ubuntu's own default archive ships on 26.04
# (which would violate the ADR's pinned-11.4-LTS requirement without saying
# so), preflight_check_mariadb_repo_available below fails closed on 26.04
# with an explicit, actionable error naming this exact gap. If MariaDB
# Foundation later publishes a resolute suite for 11.4 (or the ADR is
# amended), this mapping is the one place that needs updating.
set -eu

# --- constants -------------------------------------------------------------

SCRIPT_VERSION="1.1.0"
RELEASE_ID="2026.09.05"
DATABASE_TENANT_CAPABILITY="database.tenant.v1"
DATABASE_CONTROL_PLANE_CAPABILITY="database.control-plane.v1"

SCRIPT_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
INSTALL_ROOT=$(CDPATH='' cd -- "${SCRIPT_DIR}/../.." && pwd)
REPO_ROOT=$(CDPATH='' cd -- "${INSTALL_ROOT}/.." && pwd)

# shellcheck source=../../lib/run.sh
. "${INSTALL_ROOT}/lib/run.sh"
# shellcheck source=../../lib/json.sh
. "${INSTALL_ROOT}/lib/json.sh"
# shellcheck source=../../lib/checksum.sh
. "${INSTALL_ROOT}/lib/checksum.sh"
# shellcheck source=../../lib/log.sh
. "${INSTALL_ROOT}/lib/log.sh"
# shellcheck source=../../lib/checkpoint.sh
. "${INSTALL_ROOT}/lib/checkpoint.sh"
# shellcheck source=../../lib/preflight.sh
. "${INSTALL_ROOT}/lib/preflight.sh"
# shellcheck source=../../lib/result.sh
. "${INSTALL_ROOT}/lib/result.sh"
# shellcheck source=../../lib/offline-bundle.sh
. "${INSTALL_ROOT}/lib/offline-bundle.sh"
# shellcheck source=../../lib/firewall.sh
. "${INSTALL_ROOT}/lib/firewall.sh"
# shellcheck source=../../lib/agent.sh
. "${INSTALL_ROOT}/lib/agent.sh"
# shellcheck source=../../lib/selftest.sh
. "${INSTALL_ROOT}/lib/selftest.sh"

BASE_MANIFEST="${INSTALL_ROOT}/base/manifest.json"
FIREWALL_MANIFEST="${INSTALL_ROOT}/services/firewall/manifest.json"
NODE_HEALTH_MANIFEST="${INSTALL_ROOT}/services/node-health/manifest.json"
MARIADB_MANIFEST="${INSTALL_ROOT}/services/mariadb/manifest.json"

AGENT_BINARY_SRC="${REPO_ROOT}/agent/dist/lesta-agent-linux-amd64"

# Fixed production paths/values. Mirrors agent/cmd/lesta-agent/main.go's own
# mariadbProductionConfig() exactly -- these two files are the only two
# places these literals may ever appear; keep them in lockstep.
TENANT_PORT=3307
TENANT_DATADIR="/var/lib/lesta/mariadb/tenant"
TENANT_SOCKET="/run/mysqld/mysqld.tenant.sock"
TENANT_PIDFILE="/run/mysqld/mysqld.tenant.pid"
TENANT_LOGFILE="/var/log/mysql/mariadb-tenant.log"
TENANT_CONF_FRAGMENT="/etc/mysql/mariadb.conf.d/99-lesta-tenant.cnf"
TENANT_ADMIN_DEFAULTS_FILE="/etc/lesta/mariadb-tenant-admin.cnf"
TENANT_ADMIN_USER="lesta_agent"
MARIADB_KEYRING="/etc/apt/trusted.gpg.d/mariadb-keyring-2019.gpg"
MARIADB_SOURCES_LIST="/etc/apt/sources.list.d/mariadb.list"
MARIADB_PINNED_SERIES="11.4"

# Control-plane instance (port 3306, the default/un-suffixed mariadbd
# instance). Socket/pid-file/log-error are deliberately NOT relocated here --
# left at Ubuntu's own stock package defaults -- since this installer cannot
# independently confirm their exact stock literal paths without a real
# Ubuntu box; only datadir (required, since the relocation is the whole
# point) and port (defensive, matches the package default already) are set
# in CONTROL_PLANE_CONF_FRAGMENT below.
CONTROL_PLANE_PORT=3306
CONTROL_PLANE_DATADIR="/var/lib/lesta/mariadb/control-plane"
CONTROL_PLANE_STOCK_DATADIR="/var/lib/mysql"
CONTROL_PLANE_SOCKET="/run/mysqld/mysqld.sock"
CONTROL_PLANE_CONF_FRAGMENT="/etc/mysql/mariadb.conf.d/99-lesta-control-plane.cnf"
CONTROL_PLANE_APP_DB="lesta_control_plane"
CONTROL_PLANE_APP_USER="lesta_app"
CONTROL_PLANE_APP_CREDENTIALS_FILE="/etc/lesta/mariadb-control-plane-app.env"

# CHECKPOINT_PATH/RELEASE_PATH: this installer's own paths, distinct from
# every other leaf-service installer's own (see lib/checkpoint.sh's own top
# comment).
export CHECKPOINT_PATH="/var/lib/lesta/install/mariadb.checkpoint"
export RELEASE_PATH="/etc/lesta/mariadb-release"

# --- globals (all pre-declared for `set -u` safety) -------------------------

MODE=""
YES=0
OFFLINE_BUNDLE=""
RUN_ID=""
MANIFEST_DIGEST=""
CHANGES=""
ERRORS=""
MARIADB_CODENAME=""

# --- usage / argument parsing ----------------------------------------------

usage() {
    cat <<'USAGE' >&2
Usage: install.sh --dry-run|--apply|--version [--yes] [--offline-bundle <path>] [--help]

  --dry-run                Run preflight and report what would change. No mutation.
  --apply                  Apply the installer. Requires --yes.
  --version                Print installer version and exit.
  --yes                    Required with --apply: non-interactive confirmation.
  --offline-bundle <path>  Optional. Installs mariadb-server and
                           mariadb-client from a locally-vendored bundle
                           produced by
                           '.install/scripts/build-release.sh --mariadb-repo'
                           instead of the live repo-registration +
                           'apt-get install -y mariadb-server mariadb-client'
                           path: every .deb in <path> is sha256-verified
                           against <path>/bundle-manifest.json before any
                           mutation, then installed offline via 'dpkg -i'
                           (no network access required). The MariaDB
                           Foundation apt repository is NEVER registered on
                           this node when installing offline (no network is
                           needed for that either, since the .deb files
                           themselves are already vendored) -- this only
                           replaces the package-install step itself, not the
                           separate control-plane/tenant instance bootstrap
                           that follows it (datadir relocation/creation,
                           config fragments, account provisioning, and the
                           real SELECT 1 health probes still run exactly as
                           they do on the live path). Absent by default: the
                           live repo-registration + apt-get path is the
                           unconditional default.
  --help                   Print this message.
USAGE
}

fail_invocation() {
    printf 'install.sh: %s\n' "$1" >&2
    usage
    add_error invalid_invocation "$1" ""
    emit_result_and_exit failed "${EXIT_INVALID_INVOCATION}"
}

parse_args() {
    if [ "$#" -eq 0 ]; then
        fail_invocation "exactly one of --dry-run, --apply, or --version is required"
    fi

    while [ "$#" -gt 0 ]; do
        case "$1" in
            --dry-run)
                [ -z "${MODE}" ] || fail_invocation "only one of --dry-run/--apply/--version may be given"
                MODE="dry-run"
                shift
                ;;
            --apply)
                [ -z "${MODE}" ] || fail_invocation "only one of --dry-run/--apply/--version may be given"
                MODE="apply"
                shift
                ;;
            --version)
                [ -z "${MODE}" ] || fail_invocation "only one of --dry-run/--apply/--version may be given"
                MODE="version"
                shift
                ;;
            --yes)
                YES=1
                shift
                ;;
            --offline-bundle)
                [ "$#" -ge 2 ] || fail_invocation "--offline-bundle requires a value"
                OFFLINE_BUNDLE="$2"
                shift 2
                ;;
            --offline-bundle=*)
                OFFLINE_BUNDLE="${1#--offline-bundle=}"
                shift
                ;;
            --help)
                usage
                exit "${EXIT_OK}"
                ;;
            *)
                fail_invocation "unrecognized argument: $1"
                ;;
        esac
    done
}

validate_args() {
    case "${MODE}" in
        version)
            return 0
            ;;
        dry-run | apply) ;;
        *)
            fail_invocation "exactly one of --dry-run, --apply, or --version is required"
            ;;
    esac

    if [ "${MODE}" = "apply" ] && [ "${YES}" -ne 1 ]; then
        fail_invocation "--apply requires --yes"
    fi
}

# --- result accumulation / emission -----------------------------------------

manifest_capabilities_required_json() {
    local items item lines=""

    items=$(manifest_extract_array "${MARIADB_MANIFEST}" "depends_on")

    while IFS= read -r item; do
        [ -n "${item}" ] || continue
        lines=$(append_line "${lines}" "$(json_str "${item}")")
    done <<ITEMS
${items}
ITEMS

    json_array_from_lines "${lines}"
}

# emit_result_and_exit <status> <exit_code>
# Reports both of the manifest's own declared capabilities as provided: this
# installer bootstraps both the control-plane and tenant MariaDB instances.
emit_result_and_exit() {
    local status="$1" exit_code="$2" changes_json errors_json required_json provided_json result

    changes_json=$(json_array_from_lines "${CHANGES}")
    errors_json=$(json_array_from_lines "${ERRORS}")
    required_json=$(manifest_capabilities_required_json)
    provided_json=$(json_array_from_lines "$(append_line "$(json_str "${DATABASE_CONTROL_PLANE_CAPABILITY}")" "$(json_str "${DATABASE_TENANT_CAPABILITY}")")")

    result=$(json_join_object \
        "$(json_kv_str "schema_version" "1")" \
        "$(json_kv_str "installer" "lesta-bootstrap")" \
        "$(json_kv_str "service" "mariadb")" \
        "$(json_kv_str "mode" "${MODE:-unset}")" \
        "$(json_kv_str "status" "${status}")" \
        "$(json_kv_raw "exit_code" "${exit_code}")" \
        "$(json_kv_str "release" "${RELEASE_ID}")" \
        "$(json_kv_str "manifest_digest" "${MANIFEST_DIGEST}")" \
        "$(json_kv_raw "capabilities_provided" "${provided_json}")" \
        "$(json_kv_raw "capabilities_required" "${required_json}")" \
        "$(json_kv_raw "changes" "${changes_json}")" \
        "$(json_kv_raw "errors" "${errors_json}")")

    printf '%s\n' "${result}"
    exit "${exit_code}"
}

emit_version_and_exit() {
    MODE="version"
    emit_result_and_exit ok "${EXIT_OK}"
}

# mariadb_would_install_note prints the dry-run "how mariadb-server/
# mariadb-client would actually get installed" sentence, offline-bundle-
# aware: the live repo-registration + apt-get path is the unconditional
# default, mirroring nginx/install.sh's own nginx_would_install_note. Unlike
# every other service's own equivalent helper, the offline branch here is
# explicit that ONLY the package-install step is replaced: the MariaDB
# Foundation apt repository is never registered on this node when
# installing offline, and the separate control-plane/tenant instance
# bootstrap that follows (datadir relocation/creation, config fragments,
# account provisioning, real SELECT 1 health probes) is unaffected either
# way.
mariadb_would_install_note() {
    if [ -n "${OFFLINE_BUNDLE}" ]; then
        printf 'every vendored .deb in %s would be sha256-verified against %s/%s, then installed offline via dpkg -i (no network access required; the MariaDB Foundation apt repository would NOT be registered on this node at all for this path)%s' \
            "${OFFLINE_BUNDLE}" "${OFFLINE_BUNDLE}" "${BUNDLE_MANIFEST_FILENAME}" "$(offline_bundle_would_retain_note mariadb "${OFFLINE_BUNDLE}" mariadb-server)"
    else
        printf 'MariaDB Foundation'"'"'s apt repository (pinned to the %s series, codename %s) would be registered; mariadb-server/mariadb-client would be installed' \
            "${MARIADB_PINNED_SERIES}" "${MARIADB_CODENAME:-unresolved}"
    fi
}

emit_dry_run_result_and_exit() {
    local install_state
    install_state=$(preflight_classify_install_state)

    add_change base.os.v1 would_ensure /etc/lesta "base directories and lesta/lesta-agent identity would be created or verified; install-state classification: ${install_state}"
    add_change firewall.baseline.v1 would_apply "${NFT_TABLE_PATH}" "deny-by-default nftables table would be loaded and ${FIREWALL_UNIT_PATH} installed and enabled, registering tcp/${CONTROL_PLANE_PORT} and tcp/${TENANT_PORT}, unioned with any other service already registered on this node"
    add_change node.health.v1 would_install "${AGENT_BINARY_DEST}" "vendored agent binary would be checksum-verified and copied into place, then self-tested by creating and deleting a throwaway tenant database against the real, just-installed tenant MariaDB instance"
    add_change database.control-plane.v1 would_install "${OFFLINE_BUNDLE}" "$(mariadb_would_install_note); the default mariadb.service instance would be stopped, its stock ${CONTROL_PLANE_STOCK_DATADIR} content relocated to ${CONTROL_PLANE_DATADIR}, ${CONTROL_PLANE_CONF_FRAGMENT} written, and mariadb.service re-enabled and started; a dedicated least-privilege application account and ${CONTROL_PLANE_APP_CREDENTIALS_FILE} would be created; health would be probed with a real SELECT 1 round-trip"
    add_change database.tenant.v1 would_install "${OFFLINE_BUNDLE}" "$(mariadb_would_install_note); ${TENANT_CONF_FRAGMENT} would be written; ${TENANT_DATADIR} would be created empty and owned by mysql:mysql; mariadbd's AppArmor profile would be extended (if enforcing) to allow ${TENANT_DATADIR}; mariadb@tenant.service would be enabled and started; a dedicated admin account and ${TENANT_ADMIN_DEFAULTS_FILE} would be created; health would be probed with a real SELECT 1 round-trip"

    emit_result_and_exit would_change "${EXIT_OK}"
}

emit_apply_success_and_exit() {
    log_info "install.sh apply completed successfully"
    emit_result_and_exit applied "${EXIT_OK}"
}

# --- preflight orchestration --------------------------------------------

# preflight_check_mariadb_repo_available maps this node's Ubuntu VERSION_ID
# to the MariaDB Foundation apt repository's own codename, setting the
# MARIADB_CODENAME global on success. Fails closed (rather than silently
# falling back to whatever MariaDB version Ubuntu's own default archive
# ships) for any VERSION_ID this installer does not have a *verified*
# MariaDB-11.4 repository mapping for -- see this file's own top comment for
# the real, disclosed Ubuntu 26.04 (resolute) gap this currently includes.
preflight_check_mariadb_repo_available() {
    local version_id="$1"

    case "${version_id}" in
        24.04)
            MARIADB_CODENAME="noble"
            return 0
            ;;
        26.04)
            add_error mariadb_repo_unavailable "MariaDB Foundation's apt repository for MariaDB ${MARIADB_PINNED_SERIES} LTS does not currently publish an Ubuntu 26.04 (resolute) suite -- it currently publishes noble (24.04) and jammy (22.04) only. Installing Ubuntu's own default-archive MariaDB version instead would silently violate the ADR's pinned-11.4-LTS requirement, so this installer refuses to proceed on this OS release rather than substitute an unapproved version. This is a real, disclosed, currently-verified gap (see install.sh's own top comment for the sources), not a guess: if MariaDB Foundation later publishes a resolute suite for ${MARIADB_PINNED_SERIES}, or the ADR is amended to accept a different version on 26.04, this installer's own preflight_check_mariadb_repo_available is the one place that needs updating." "/etc/os-release"
            return 1
            ;;
        *)
            add_error mariadb_repo_unmapped "detected Ubuntu VERSION_ID=${version_id}, which this installer has no verified MariaDB Foundation repository codename mapping for" "/etc/os-release"
            return 1
            ;;
    esac
}

# preflight_check_tenant_port_free fails only when tcp/3307 (the tenant
# instance's own port) is already bound by something other than mariadbd. A
# rerun against an already-bootstrapped node finding its own already-running
# mariadbd on 3307 is convergence, not a conflict.
preflight_check_tenant_port_free() {
    preflight_check_port_free "${TENANT_PORT}" tcp mariadbd
}

# preflight_check_control_plane_port_free mirrors the tenant check above for
# tcp/3306. On a bare node this is almost always already bound by the
# package's own just-auto-started default mariadb.service, which
# preflight_check_port_free correctly treats as convergence (mariadbd is the
# expected owner), not a conflict.
preflight_check_control_plane_port_free() {
    preflight_check_port_free "${CONTROL_PLANE_PORT}" tcp mariadbd
}

run_preflight() {
    local os_id os_version_id arch supported failed=0 dir

    if [ "$(id -u)" -ne 0 ]; then
        add_error not_root "install.sh must run as root (uid 0)" ""
        emit_result_and_exit failed "${EXIT_UNSUPPORTED_PLATFORM}"
    fi

    os_id=$(preflight_os_release_field ID)
    os_version_id=$(preflight_os_release_field VERSION_ID)
    supported=$(manifest_extract_array "${MARIADB_MANIFEST}" "supported_ubuntu")

    if [ "${os_id}" != "ubuntu" ] || ! printf '%s\n' "${supported}" | grep -Fxq "${os_version_id}"; then
        add_error unsupported_os "detected ${os_id:-unknown} ${os_version_id:-unknown}; supported: $(printf '%s' "${supported}" | tr '\n' ' ')" "/etc/os-release"
        emit_result_and_exit failed "${EXIT_UNSUPPORTED_PLATFORM}"
    fi

    arch=$(dpkg --print-architecture)
    if [ "${arch}" != "amd64" ]; then
        add_error unsupported_architecture "detected architecture ${arch}; only amd64 is supported (the vendored agent binary is amd64-only)" ""
        emit_result_and_exit failed "${EXIT_UNSUPPORTED_PLATFORM}"
    fi

    log_info "platform ok: ubuntu ${os_version_id} ${arch} kernel=$(uname -r)"

    # The repo-availability check can itself fail this node's OS release
    # outright (the disclosed 26.04 gap); it must run before any apt
    # mutation is attempted, matching every other hard preflight gate here.
    # Skipped entirely when --offline-bundle is given: the offline path
    # never registers the MariaDB Foundation repository at all (the .deb
    # files are already vendored, so no network access -- and no repo --
    # is needed for the package-install step), so this node's OS release
    # must not be rejected here just because 26.04 has no *repository*
    # mapping; MARIADB_CODENAME is left unset in that case and never read by
    # the offline path.
    if [ -z "${OFFLINE_BUNDLE}" ]; then
        if ! preflight_check_mariadb_repo_available "${os_version_id}"; then
            emit_result_and_exit failed "${EXIT_PREFLIGHT_CONFLICT}"
        fi
    fi

    for dir in /etc /var/lib /var/log; do
        preflight_check_capacity "${dir}" || failed=1
    done

    preflight_check_control_plane_port_free || failed=1
    preflight_check_tenant_port_free || failed=1
    preflight_check_lesta_identity || failed=1

    if [ -n "${OFFLINE_BUNDLE}" ]; then
        preflight_check_offline_bundle_present "${OFFLINE_BUNDLE}" || failed=1
    fi

    if [ "${failed}" -ne 0 ]; then
        emit_result_and_exit failed "${EXIT_PREFLIGHT_CONFLICT}"
    fi

    log_info "preflight passed (MariaDB Foundation repo codename: ${MARIADB_CODENAME:-n/a, offline bundle})"
}

# --- phase 1: bootstrap_base -------------------------------------------
#
# Identical to nginx/install.sh's and bind9/install.sh's own bootstrap_base
# (capability-agnostic). Duplicated rather than shared, matching this
# project's own established "concrete shared lib files only" scope.

ensure_lesta_group() {
    getent group lesta >/dev/null 2>&1 || groupadd --system lesta
}

bootstrap_base() {
    log_info "bootstrap_base: ensuring lesta group/user and base directories"

    ensure_lesta_group

    if ! getent passwd lesta-agent >/dev/null 2>&1; then
        useradd --system --gid lesta --home-dir /var/lib/lesta --no-create-home \
            --shell /usr/sbin/nologin --comment "LESta node agent" lesta-agent \
            || fail_step "${EXIT_MUTATION_FAILURE}" useradd_failed /etc/passwd "failed to create system user lesta-agent"
        add_change base.os.v1 created /etc/passwd "created system user lesta-agent (via useradd, NSS-mediated)"
    else
        add_change base.os.v1 verified /etc/passwd "system user lesta-agent already exists"
    fi

    install -d -m 0750 -o root -g lesta /etc/lesta || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /etc/lesta "failed to create /etc/lesta"
    install -d -m 0751 -o root -g lesta /var/lib/lesta || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta "failed to create /var/lib/lesta"
    install -d -m 0750 -o root -g lesta /var/log/lesta || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/log/lesta "failed to create /var/log/lesta"
    add_change base.os.v1 ensured /etc/lesta "directory present, mode 0750 root:lesta"
    add_change base.layout.v1 ensured /var/lib/lesta "directory present, mode 0750 root:lesta"
    add_change base.layout.v1 ensured /var/log/lesta "directory present, mode 0750 root:lesta"

    update-ca-certificates >/dev/null 2>&1 || true
    add_change base.tls.v1 refreshed /etc/ssl/certs "update-ca-certificates run"

    checkpoint_write bootstrap_base "${MANIFEST_DIGEST}"
    log_info "bootstrap_base complete"
}

# --- phase 2: bootstrap_firewall_baseline -------------------------------
#
# Deliberately NOT the shared bootstrap_firewall_baseline (lib/firewall.sh):
# that helper would work fine here too (this service's own manifest.json
# declares exactly the two ports this installer is responsible for, control-
# plane's 3306 and tenant's 3307, together), but bootstrap_firewall_baseline_
# mariadb below reimplements the same ensure-dirs + register + render
# sequence explicitly, matching every other leaf-service installer's own
# "own capability-scoped firewall phase" pattern.

bootstrap_firewall_baseline_mariadb() {
    log_info "bootstrap_firewall_baseline_mariadb: registering tcp/${CONTROL_PLANE_PORT} and tcp/${TENANT_PORT}"

    install -d -m 0750 -o root -g lesta "${FIREWALL_DIR}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${FIREWALL_DIR}" "failed to create ${FIREWALL_DIR}"
    install -d -m 0750 -o root -g lesta "${FIREWALL_PORTS_DIR}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${FIREWALL_PORTS_DIR}" "failed to create ${FIREWALL_PORTS_DIR}"
    add_change firewall.baseline.v1 ensured "${FIREWALL_DIR}" "directory present, mode 0750 root:lesta"

    manifest_extract_port_specs "${MARIADB_MANIFEST}" | grep -E "tcp (${CONTROL_PLANE_PORT}|${TENANT_PORT})" > "${FIREWALL_PORTS_DIR}/mariadb.ports.tmp" \
        || fail_step "${EXIT_MUTATION_FAILURE}" firewall_fragment_write_failed "${FIREWALL_PORTS_DIR}/mariadb.ports" "manifest.json's own ports[] did not contain the expected tcp/${CONTROL_PLANE_PORT} and tcp/${TENANT_PORT} entries"
    chmod 0640 "${FIREWALL_PORTS_DIR}/mariadb.ports.tmp"
    chown root:lesta "${FIREWALL_PORTS_DIR}/mariadb.ports.tmp" 2>/dev/null || true
    mv -f "${FIREWALL_PORTS_DIR}/mariadb.ports.tmp" "${FIREWALL_PORTS_DIR}/mariadb.ports"

    firewall_render_and_apply

    log_info "bootstrap_firewall_baseline_mariadb complete"
}

# --- phase 3: ensure_mariadb_package_installed --------------------------

# install_mariadb_offline_bundle <bundle_dir>
# The --offline-bundle counterpart to the live repo-registration +
# 'apt-get install -y mariadb-server mariadb-client' path below: verifies
# every vendored .deb first (fail closed, no mutation before this returns),
# then installs via 'dpkg -i', requiring no network access at all. Unlike
# every other service's own equivalent wrapper, this one is called from a
# branch that also skips the ENTIRE repo-registration sequence outright (see
# ensure_mariadb_package_installed below): the MariaDB Foundation apt
# repository must never be registered on this node at all when installing
# offline, not merely have its failure ignored, since the .deb files
# themselves are already vendored and no network access -- and no repo -- is
# needed for this step either way.
install_mariadb_offline_bundle() {
    local bundle_dir="$1" out

    log_info "ensure_mariadb_package_installed: installing mariadb-server/mariadb-client from offline bundle ${bundle_dir} (no network access required; the MariaDB Foundation repository is never registered on this node for this path)"

    offline_bundle_retain_generation mariadb "${bundle_dir}" mariadb-server

    verify_offline_bundle_artifacts "${bundle_dir}"
    add_change mariadb verified "${bundle_dir}" "every vendored .deb in the offline bundle matched its ${BUNDLE_MANIFEST_FILENAME} sha256; proceeding to offline install"

    if ! out=$(install_offline_bundle_debs "${bundle_dir}"); then
        add_error dpkg_install_failed "$(printf '%s' "${out}" | tr '\n' ' ')" "${bundle_dir}"
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi

    offline_bundle_snapshot_current mariadb "${bundle_dir}"
    add_change mariadb generation_retained "/var/lib/lesta/mariadb/bundle-generations" "offline-bundle generation bookkeeping updated: current/ now reflects this bundle; previous/ holds the prior generation if the mariadb-server version actually changed. One shared mariadb-server package underlies both the control-plane and tenant instances, so this single retention root covers both."
}

# mariadb_fail_health <exit_code> <error_code> <path> <message>
# Wraps every package/instance-level health-check failure site in
# install_mariadb_control_plane and install_mariadb_tenant below (both
# instances' own systemctl enable and mariadb_health_probe-driven
# failures): on the live path (OFFLINE_BUNDLE empty) this is byte-for-byte
# the same plain fail_step call these sites always made; only the
# --offline-bundle path additionally attempts an automatic rollback to the
# previous retained generation first. One shared mariadb-server package
# underlies BOTH instances, so a rollback triggered by either instance's
# own health failure restarts BOTH mariadb.service and
# mariadb@tenant.service, never just the one instance whose check failed.
mariadb_fail_health() {
    if [ -n "${OFFLINE_BUNDLE}" ]; then
        offline_bundle_fail_health_with_rollback "$1" "$2" "$3" "$4" mariadb "mariadb.service mariadb@tenant.service"
    else
        fail_step "$1" "$2" "$3" "$4"
    fi
}

# ensure_mariadb_package_installed pins the MariaDB Foundation apt
# repository, installs mariadb-server/mariadb-client, and verifies both the
# installed version and its provenance -- or, when --offline-bundle is
# given, installs the same two packages from the vendored bundle instead,
# skipping repo registration entirely. Shared by both instance phases below
# (control-plane and tenant): there is exactly one mariadb-server package on
# this node, so this step runs once from main(), before either
# instance-specific phase.
ensure_mariadb_package_installed() {
    local out installed_version installed_version_no_epoch

    if [ -n "${OFFLINE_BUNDLE}" ]; then
        log_info "ensure_mariadb_package_installed: installing from offline bundle (no repo registration)"

        install_mariadb_offline_bundle "${OFFLINE_BUNDLE}"

        installed_version=$(dpkg-query -W -f='${Version}' mariadb-server 2>/dev/null || true)
        if [ -z "${installed_version}" ]; then
            fail_step "${EXIT_MUTATION_FAILURE}" apt_install_unverifiable "" "dpkg-query could not report an installed mariadb-server version after dpkg -i from the offline bundle"
        fi

        # Same series check as the live path below (the vendored bundle
        # must still be a real 11.4.x build), but deliberately WITHOUT the
        # live path's own "apt-cache policy shows deb.mariadb.org" provenance
        # check immediately below this comment: that check depends on a
        # live, registered apt source to compare against, which the offline
        # path never has by design (see this function's own top comment).
        # sha256 verification against bundle-manifest.json, already done by
        # install_mariadb_offline_bundle above, is this path's own
        # provenance guarantee instead.
        installed_version_no_epoch="${installed_version#*:}"
        case "${installed_version_no_epoch}" in
            "${MARIADB_PINNED_SERIES}".*) ;;
            *)
                add_error mariadb_wrong_version_installed "expected a ${MARIADB_PINNED_SERIES}.* version, but dpkg-query reports ${installed_version}" ""
                emit_result_and_exit failed "${EXIT_VERIFICATION_FAILURE}"
                ;;
        esac

        add_change mariadb installed "${OFFLINE_BUNDLE}" "dpkg -i ${OFFLINE_BUNDLE}/*.deb succeeded (fully offline, no network access used, MariaDB Foundation repository never registered); dpkg-query reports version ${installed_version}"
    else
        log_info "ensure_mariadb_package_installed: pinning MariaDB ${MARIADB_PINNED_SERIES} (codename ${MARIADB_CODENAME}) and installing"

        # --- pin the MariaDB Foundation apt repository ----------------------
        #
        # Manual (non-mariadb_repo_setup-script) setup, deliberately: piping a
        # remote script into `sudo bash` is exactly the kind of unauditable
        # supply-chain step .install/INSTALLER-CONTRACT.md's own "Supply chain"
        # section warns against, so this installer downloads the same GPG
        # keyring MariaDB's own documentation names explicitly (installing-
        # mariadb-deb-files.md / gpg.md: key fingerprint 177F 4010 FE56 CA33
        # 3630 0305 F165 6F24 C74C D1D8, "MariaDB Community Server Debian /
        # Ubuntu key") and writes the sources list entry by hand instead.
        if ! out=$(curl -fsSL -o "${MARIADB_KEYRING}" "https://supplychain.mariadb.com/mariadb-keyring-2019.gpg" 2>&1); then
            add_error mariadb_keyring_fetch_failed "$(printf '%s' "${out}" | tr '\n' ' ')" "${MARIADB_KEYRING}"
            emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
        fi
        chmod 0644 "${MARIADB_KEYRING}"
        add_change mariadb installed "${MARIADB_KEYRING}" "MariaDB Foundation's release signing key (fingerprint 177F4010FE56CA333630 0305F1656F24C74CD1D8) downloaded to the modern apt trusted-keyring location"

        cat > "${MARIADB_SOURCES_LIST}.tmp" <<SOURCES
# MariaDB ${MARIADB_PINNED_SERIES} LTS repository (MariaDB Foundation), pinned to the
# ${MARIADB_PINNED_SERIES} major series so it tracks that series' own minor updates.
# Managed by LESta; do not edit by hand.
deb [arch=amd64 signed-by=${MARIADB_KEYRING}] https://deb.mariadb.org/${MARIADB_PINNED_SERIES}/ubuntu ${MARIADB_CODENAME} main
SOURCES
        mv -f "${MARIADB_SOURCES_LIST}.tmp" "${MARIADB_SOURCES_LIST}"
        add_change mariadb installed "${MARIADB_SOURCES_LIST}" "apt source pinned to MariaDB ${MARIADB_PINNED_SERIES} (codename ${MARIADB_CODENAME})"

        if ! out=$(apt-get update 2>&1); then
            add_error apt_update_failed "$(printf '%s' "${out}" | tr '\n' ' ')" "${MARIADB_SOURCES_LIST}"
            emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
        fi

        if ! out=$(apt-get install -y mariadb-server mariadb-client 2>&1); then
            add_error apt_install_failed "$(printf '%s' "${out}" | tr '\n' ' ')" ""
            emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
        fi

        installed_version=$(dpkg-query -W -f='${Version}' mariadb-server 2>/dev/null || true)
        if [ -z "${installed_version}" ]; then
            fail_step "${EXIT_MUTATION_FAILURE}" apt_install_unverifiable "" "dpkg-query could not report an installed mariadb-server version after apt-get install"
        fi

        # Debian/Ubuntu package versions may carry an "epoch:" prefix
        # (dpkg-query's own ${Version} field includes it when present); MariaDB
        # Foundation's own noble build is versioned "1:11.4.13+maria~ubu2404",
        # confirmed directly via a real CI run -- a bare
        # `case "${installed_version}" in "${MARIADB_PINNED_SERIES}".*)` never
        # matches a string starting "1:", producing a false "wrong version"
        # failure even when the correct, pinned package installed successfully.
        # Strip up to and including the first ':' (a no-op if there is none)
        # before comparing the series prefix.
        installed_version_no_epoch="${installed_version#*:}"

        case "${installed_version_no_epoch}" in
            "${MARIADB_PINNED_SERIES}".*) ;;
            *)
                add_error mariadb_wrong_version_installed "expected a ${MARIADB_PINNED_SERIES}.* version, but dpkg-query reports ${installed_version}" ""
                emit_result_and_exit failed "${EXIT_VERIFICATION_FAILURE}"
                ;;
        esac

        # The version prefix alone only proves "some 11.4.x is installed", not
        # that it came from OUR pinned, signed repository rather than Ubuntu's
        # own default archive happening to ship a same-numbered build (the ADR's
        # own pinning requirement is about verified provenance, not just a
        # matching version string). apt-cache policy's own output marks the
        # currently-installed version with a leading "***" and lists its actual
        # source origin on the very next line; checking that line names
        # deb.mariadb.org is a real provenance check, not a guess.
        if ! apt-cache policy mariadb-server 2>/dev/null | awk '/\*\*\*/{getline; print}' | grep -q 'deb\.mariadb\.org'; then
            add_error mariadb_wrong_repo_installed "mariadb-server ${installed_version} is installed, but apt-cache policy does not show deb.mariadb.org as its source -- the pinned MariaDB Foundation repository was likely shadowed by another apt source offering the same version number" ""
            emit_result_and_exit failed "${EXIT_VERIFICATION_FAILURE}"
        fi

        add_change mariadb installed "" "apt-get install -y mariadb-server mariadb-client succeeded; dpkg-query reports version ${installed_version}, apt-cache policy confirms deb.mariadb.org as its source"
    fi

    # mysql (the package's own system user, running as the mariadbd worker
    # identity for BOTH instances) has no reason to already be a member of
    # the lesta group. /var/lib/lesta itself is 0750 root:lesta (created by
    # bootstrap_base); mysql is neither root nor a lesta group member, so it
    # cannot even traverse into /var/lib/lesta at all, let alone reach
    # /var/lib/lesta/mariadb/control-plane or /var/lib/lesta/mariadb/tenant
    # beneath it -- the exact same class of gap bind9's own installer already
    # hit and fixed for named/bind and apache's own installer hit and fixed
    # for www-data.
    #
    # This grant must run here, in the ONE shared phase both instance phases
    # call, before either of them starts mariadbd against a /var/lib/lesta
    # path. Confirmed directly via a real CI run: this call used to live
    # inside install_mariadb_tenant() alone, which was fine when tenant ran
    # first (a real supplementary-group grant, followed by that same
    # function's own first `systemctl enable --now mariadb@tenant.service`,
    # is sufficient since supplementary groups are fixed at process exec time
    # and that exec only ever happens after the grant). Once
    # install_mariadb_control_plane() started running BEFORE install_
    # mariadb_tenant() in main(), that ordering assumption broke: the
    # control-plane phase's own mariadbd re-exec (systemctl stop then
    # enable --now mariadb.service, against the just-relocated datadir) hit
    # "Can't create test file '.../control-plane/<host>.lower-test' (Errcode:
    # 13 'Permission denied')" and failed its own health probe, since mysql
    # still wasn't a lesta group member at that point. Moving the grant here,
    # before either instance phase runs, fixes both orders at once.
    usermod -aG lesta mysql || fail_step "${EXIT_MUTATION_FAILURE}" usermod_failed "" "usermod -aG lesta mysql failed"
    add_change mariadb group_membership_granted "" "mysql added to the lesta group, so mariadbd's own worker (both instances) can traverse /var/lib/lesta to reach its own datadir"

    log_info "ensure_mariadb_package_installed complete"
}

# --- phase 4: install_mariadb_control_plane ------------------------------

# mariadb_health_probe polls a real `SELECT 1` round-trip over the given
# instance's own Unix socket until it succeeds or timeout elapses. A real
# client round-trip is a stronger readiness signal than a bare TCP/socket
# connect check: mariadbd's own InnoDB initialization (and, on a fresh
# datadir, mariadb-install-db itself) can take a real moment after the
# process starts.
mariadb_health_probe() {
    local socket="$1" attempt=0

    while [ "${attempt}" -lt 60 ]; do
        if mariadb --socket="${socket}" -u root -e "SELECT 1;" >/dev/null 2>&1; then
            return 0
        fi

        attempt=$((attempt + 1))
        sleep 1
    done

    return 1
}

# install_mariadb_control_plane relocates the default mariadb.service
# instance (almost certainly already auto-started by the package's own
# postinst against the stock /var/lib/mysql datadir) onto this project's own
# managed datadir path, then creates a dedicated least-privilege application
# account for Laravel to use.
install_mariadb_control_plane() {
    log_info "install_mariadb_control_plane: activating database.control-plane.v1"

    local out app_password apparmor_local_mariadbd

    # --- idempotency short-circuit: already migrated on a prior run --------
    if [ -f "${CONTROL_PLANE_CONF_FRAGMENT}" ] && grep -qF "datadir = ${CONTROL_PLANE_DATADIR}" "${CONTROL_PLANE_CONF_FRAGMENT}" 2>/dev/null; then
        log_info "install_mariadb_control_plane: ${CONTROL_PLANE_CONF_FRAGMENT} already points at ${CONTROL_PLANE_DATADIR}; treating this as a rerun against an already-migrated instance"
        add_change database.control-plane.v1 verified "${CONTROL_PLANE_CONF_FRAGMENT}" "already migrated to ${CONTROL_PLANE_DATADIR} by a prior apply"

        systemctl daemon-reload || fail_step "${EXIT_MUTATION_FAILURE}" systemctl_daemon_reload_failed "" "systemctl daemon-reload failed"
        if ! out=$(systemctl enable --now mariadb.service 2>&1); then
            mariadb_fail_health "${EXIT_HEALTH_FAILURE}" mariadb_control_plane_enable_failed "" "$(printf '%s' "${out}" | tr '\n' ' ')"
        fi
        add_change database.control-plane.v1 enabled "" "systemctl enable --now mariadb.service succeeded"

        mariadb_health_probe "${CONTROL_PLANE_SOCKET}" || mariadb_fail_health "${EXIT_HEALTH_FAILURE}" mariadb_health_check_failed "${CONTROL_PLANE_SOCKET}" "a real SELECT 1 round-trip over ${CONTROL_PLANE_SOCKET} did not succeed within 60s of enabling mariadb.service"
        add_change database.control-plane.v1 healthy "" "a real SELECT 1 round-trip over ${CONTROL_PLANE_SOCKET} succeeded"

        checkpoint_write install_mariadb_control_plane "${MANIFEST_DIGEST}"
        log_info "install_mariadb_control_plane complete (already-migrated rerun)"
        return 0
    fi

    # --- fresh migration: relocate the already-live default instance -------
    systemctl stop mariadb.service 2>/dev/null || true

    install -d -m 0750 -o mysql -g mysql /var/lib/lesta/mariadb || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta/mariadb "failed to create /var/lib/lesta/mariadb"
    install -d -m 0750 -o mysql -g mysql "${CONTROL_PLANE_DATADIR}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${CONTROL_PLANE_DATADIR}" "failed to create ${CONTROL_PLANE_DATADIR}"

    if [ -d "${CONTROL_PLANE_STOCK_DATADIR}" ] && [ -n "$(ls -A "${CONTROL_PLANE_STOCK_DATADIR}" 2>/dev/null)" ]; then
        # cp -a then rm -rf, not a bare `mv .../* ...`: preserves ownership
        # and permissions of every relocated file/directory explicitly and
        # tolerates dotfiles the package may have written, which a bare glob
        # move can silently skip.
        cp -a "${CONTROL_PLANE_STOCK_DATADIR}/." "${CONTROL_PLANE_DATADIR}/" || fail_step "${EXIT_MUTATION_FAILURE}" datadir_relocate_failed "${CONTROL_PLANE_DATADIR}" "failed to copy ${CONTROL_PLANE_STOCK_DATADIR} content into ${CONTROL_PLANE_DATADIR}"
        find "${CONTROL_PLANE_STOCK_DATADIR:?}" -mindepth 1 -delete 2>/dev/null || true
        add_change database.control-plane.v1 relocated "${CONTROL_PLANE_DATADIR}" "already-initialized data relocated from the stock ${CONTROL_PLANE_STOCK_DATADIR} (created by mariadb-server's own package postinst) to this project's own managed datadir path"
    else
        # Real edge case: the package postinst did not auto-initialize the
        # stock datadir (or it was already emptied by a prior partial run).
        # Unlike the tenant instance, the default mariadb.service's own
        # ExecStartPre behavior against an already-relocated, non-stock path
        # is not a mechanism this installer relies on -- initialize
        # explicitly here instead.
        log_info "install_mariadb_control_plane: ${CONTROL_PLANE_STOCK_DATADIR} is empty or absent; running mariadb-install-db directly against ${CONTROL_PLANE_DATADIR}"
        if ! out=$(mariadb-install-db --datadir="${CONTROL_PLANE_DATADIR}" --user=mysql 2>&1); then
            add_error mariadb_install_db_failed "$(printf '%s' "${out}" | tr '\n' ' ')" "${CONTROL_PLANE_DATADIR}"
            emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
        fi
        add_change database.control-plane.v1 installed "${CONTROL_PLANE_DATADIR}" "mariadb-install-db run directly against an empty datadir (the stock ${CONTROL_PLANE_STOCK_DATADIR} was empty or absent)"
    fi

    chown -R mysql:mysql "${CONTROL_PLANE_DATADIR}" || fail_step "${EXIT_MUTATION_FAILURE}" chown_failed "${CONTROL_PLANE_DATADIR}" "failed to chown ${CONTROL_PLANE_DATADIR} to mysql:mysql"
    chmod 0750 "${CONTROL_PLANE_DATADIR}" || fail_step "${EXIT_MUTATION_FAILURE}" chmod_failed "${CONTROL_PLANE_DATADIR}" "failed to chmod ${CONTROL_PLANE_DATADIR} to 0750"
    add_change database.control-plane.v1 ensured "${CONTROL_PLANE_DATADIR}" "control-plane datadir present, mode 0750 mysql:mysql"

    # --- [mysqld] config fragment: read by the DEFAULT mariadbd instance
    # (no --defaults-group-suffix), unlike the tenant fragment's own
    # [mysqld.tenant] group-suffix mechanism -- this is the only fragment on
    # this node targeting the plain, un-suffixed group. Only datadir
    # (required) and port (defensive, already the package default) are set;
    # socket/pid-file/log-error are left at Ubuntu's own stock package
    # defaults, deliberately not relocated (see this file's own top comment
    # and the CONTROL_PLANE_* constants block for why).
    cat > "${CONTROL_PLANE_CONF_FRAGMENT}.tmp" <<CONF
# Managed by LESta. Do not edit by hand.
#
# Read by the DEFAULT mariadbd instance (systemctl start mariadb.service,
# no --defaults-group-suffix) -- the plain [mysqld] group, not a
# group-suffixed one. Only datadir and port are overridden here; socket,
# pid-file, and log-error are left at Ubuntu's own stock package defaults.
[mysqld]
port = ${CONTROL_PLANE_PORT}
datadir = ${CONTROL_PLANE_DATADIR}
CONF
    mv -f "${CONTROL_PLANE_CONF_FRAGMENT}.tmp" "${CONTROL_PLANE_CONF_FRAGMENT}"
    chmod 0644 "${CONTROL_PLANE_CONF_FRAGMENT}"
    add_change database.control-plane.v1 installed "${CONTROL_PLANE_CONF_FRAGMENT}" "[mysqld] option group written with datadir and port set explicitly"

    # --- AppArmor: same local-override file the tenant phase already
    # appends to, extended with a second allow-block for the control-plane
    # datadir if not already present -----------------------------------
    apparmor_local_mariadbd="/etc/apparmor.d/local/usr.sbin.mariadbd"
    if [ -f "${apparmor_local_mariadbd}" ]; then
        if ! grep -qF "${CONTROL_PLANE_DATADIR}" "${apparmor_local_mariadbd}" 2>/dev/null; then
            {
                printf '\n# Added by LESta: mariadbd must read and write its own control-plane datadir here.\n'
                printf '%s/ r,\n' "${CONTROL_PLANE_DATADIR}"
                printf '%s/** rwk,\n' "${CONTROL_PLANE_DATADIR}"
            } >> "${apparmor_local_mariadbd}" || fail_step "${EXIT_MUTATION_FAILURE}" apparmor_override_write_failed "${apparmor_local_mariadbd}" "failed to append the ${CONTROL_PLANE_DATADIR} allowance to ${apparmor_local_mariadbd}"
            add_change database.control-plane.v1 apparmor_extended "${apparmor_local_mariadbd}" "granted mariadbd read/write access to ${CONTROL_PLANE_DATADIR} via Ubuntu's own local-override include point"

            if command -v apparmor_parser >/dev/null 2>&1; then
                apparmor_parser -r /etc/apparmor.d/usr.sbin.mariadbd || fail_step "${EXIT_MUTATION_FAILURE}" apparmor_reload_failed "${apparmor_local_mariadbd}" "apparmor_parser -r /etc/apparmor.d/usr.sbin.mariadbd failed"
                add_change database.control-plane.v1 apparmor_reloaded "" "apparmor_parser -r reloaded the mariadbd profile with the ${CONTROL_PLANE_DATADIR} allowance"
            fi
        fi
    else
        log_info "AppArmor local override file for mariadbd not present (${apparmor_local_mariadbd}); assuming AppArmor is not enforcing mariadbd on this host, skipping"
    fi

    # --- enable + start the control-plane instance --------------------------
    systemctl daemon-reload || fail_step "${EXIT_MUTATION_FAILURE}" systemctl_daemon_reload_failed "" "systemctl daemon-reload failed"

    if ! out=$(systemctl enable --now mariadb.service 2>&1); then
        mariadb_fail_health "${EXIT_HEALTH_FAILURE}" mariadb_control_plane_enable_failed "" "$(printf '%s' "${out}" | tr '\n' ' ')"
    fi
    add_change database.control-plane.v1 enabled "" "systemctl enable --now mariadb.service succeeded, reading the relocated ${CONTROL_PLANE_DATADIR}"

    mariadb_health_probe "${CONTROL_PLANE_SOCKET}" || mariadb_fail_health "${EXIT_HEALTH_FAILURE}" mariadb_health_check_failed "${CONTROL_PLANE_SOCKET}" "a real SELECT 1 round-trip over ${CONTROL_PLANE_SOCKET} did not succeed within 60s of enabling mariadb.service"
    add_change database.control-plane.v1 healthy "" "a real SELECT 1 round-trip over ${CONTROL_PLANE_SOCKET} succeeded"

    # --- dedicated least-privilege Laravel application account -------------
    #
    # Deliberately NOT the tenant admin account's broad shape: schema-scoped
    # to CONTROL_PLANE_APP_DB only, never *.*, never WITH GRANT OPTION. This
    # application never needs to create other databases or manage other
    # users, unlike the tenant admin account (which the agent uses to
    # provision per-tenant databases dynamically).
    # CREATE OR REPLACE USER, for the identical reason the tenant admin
    # account uses it: a fresh password is generated on every apply, and the
    # very next statement always re-grants the same fixed privilege set
    # regardless, so REPLACE-then-regrant is safe here exactly as it is for
    # the tenant admin account.
    app_password=$(od -An -tx1 -N24 /dev/urandom | tr -d ' \n')

    if ! out=$(mariadb --socket="${CONTROL_PLANE_SOCKET}" -u root <<APPSQL 2>&1
CREATE DATABASE IF NOT EXISTS \`${CONTROL_PLANE_APP_DB}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE OR REPLACE USER '${CONTROL_PLANE_APP_USER}'@'127.0.0.1' IDENTIFIED BY '${app_password}';
GRANT ALL PRIVILEGES ON \`${CONTROL_PLANE_APP_DB}\`.* TO '${CONTROL_PLANE_APP_USER}'@'127.0.0.1';
FLUSH PRIVILEGES;
APPSQL
    ); then
        add_error mariadb_app_account_failed "$(printf '%s' "${out}" | tr '\n' ' ')" ""
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi
    add_change database.control-plane.v1 installed "" "database ${CONTROL_PLANE_APP_DB} and dedicated application account ${CONTROL_PLANE_APP_USER}@127.0.0.1 created, schema-scoped, never WITH GRANT OPTION"

    cat > "${CONTROL_PLANE_APP_CREDENTIALS_FILE}.tmp" <<CREDS
DB_CONNECTION=mariadb
DB_HOST=127.0.0.1
DB_PORT=${CONTROL_PLANE_PORT}
DB_DATABASE=${CONTROL_PLANE_APP_DB}
DB_USERNAME=${CONTROL_PLANE_APP_USER}
DB_PASSWORD=${app_password}
CREDS
    chmod 0600 "${CONTROL_PLANE_APP_CREDENTIALS_FILE}.tmp"
    chown root:root "${CONTROL_PLANE_APP_CREDENTIALS_FILE}.tmp" 2>/dev/null || true
    mv -f "${CONTROL_PLANE_APP_CREDENTIALS_FILE}.tmp" "${CONTROL_PLANE_APP_CREDENTIALS_FILE}"
    add_change database.control-plane.v1 installed "${CONTROL_PLANE_APP_CREDENTIALS_FILE}" "Laravel .env-shaped DB_* credentials written, mode 0600 root:root (a root-only file, not encrypted at rest in any cryptographic sense); an operator merges its contents into their own Laravel .env by hand, since install.sh has no reliable way to locate a Laravel checkout's .env on an arbitrary node, the password itself is never logged"

    checkpoint_write install_mariadb_control_plane "${MANIFEST_DIGEST}"
    log_info "install_mariadb_control_plane complete"
}

# --- phase 5: install_mariadb_tenant ------------------------------------

install_mariadb_tenant() {
    log_info "install_mariadb_tenant: activating database.tenant.v1"

    local out admin_password apparmor_local_mariadbd

    # mysql's own lesta-group membership (needed to traverse /var/lib/lesta
    # to reach this datadir) is granted once, shared by both instance
    # phases, in ensure_mariadb_package_installed above.

    # --- tenant datadir: created empty and correctly owned only -----------
    #
    # The packaged mariadb@.service unit's own ExecStartPre runs
    # mariadb-install-db automatically against this datadir the first time
    # mariadb@tenant.service starts (see this file's own top comment); this
    # installer never runs mariadb-install-db itself.
    install -d -m 0750 -o mysql -g mysql "${TENANT_DATADIR}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${TENANT_DATADIR}" "failed to create ${TENANT_DATADIR}"
    add_change database.tenant.v1 ensured "${TENANT_DATADIR}" "tenant datadir present, empty, mode 0750 mysql:mysql (mariadb-install-db populates it automatically on first start)"

    # --- [mysqld.tenant] config fragment: every instance-distinguishing key
    # explicitly set (see this file's own top comment for why omitting any
    # one of these risks silently sharing the control-plane instance's own
    # socket/datadir/port) -------------------------------------------------
    cat > "${TENANT_CONF_FRAGMENT}.tmp" <<CONF
# Managed by LESta. Do not edit by hand.
#
# Read only by an instance started with --defaults-group-suffix=.tenant
# (i.e. via \`systemctl start mariadb@tenant.service\`), in ADDITION to every
# base [mysqld] group already read from mariadb.conf.d/*.cnf -- every key
# that would otherwise default to the control-plane instance's own value
# must be set explicitly here.
[mysqld.tenant]
port = ${TENANT_PORT}
datadir = ${TENANT_DATADIR}
socket = ${TENANT_SOCKET}
pid-file = ${TENANT_PIDFILE}
bind-address = 127.0.0.1
log-error = ${TENANT_LOGFILE}
CONF
    mv -f "${TENANT_CONF_FRAGMENT}.tmp" "${TENANT_CONF_FRAGMENT}"
    chmod 0644 "${TENANT_CONF_FRAGMENT}"
    add_change database.tenant.v1 installed "${TENANT_CONF_FRAGMENT}" "[mysqld.tenant] option group written with every instance-distinguishing key set explicitly (port ${TENANT_PORT}, datadir, socket, pid-file, bind-address, log-error)"

    # --- AppArmor: expected repeat of bind9's own gap for named/
    # /var/lib/lesta/bind, adapted for mariadbd's own profile -------------
    #
    # Ubuntu's mariadbd AppArmor profile (when actually enforcing -- some
    # packaged builds ship it as an intentionally-empty/disabled stub, in
    # which case this block is a harmless no-op) almost certainly allow-lists
    # only /var/lib/mysql/**, not /var/lib/lesta/mariadb/**. This has NOT
    # been hands-on-confirmed against a real enforcing Ubuntu AppArmor
    # profile by this phase's own work (unlike bind9's own gap, which CI
    # actually hit and fixed); this block applies the identical two-part fix
    # bind9/install.sh already established (local-override append + reload)
    # defensively, to be confirmed against real CI evidence.
    apparmor_local_mariadbd="/etc/apparmor.d/local/usr.sbin.mariadbd"
    if [ -f "${apparmor_local_mariadbd}" ]; then
        if ! grep -qF "${TENANT_DATADIR}" "${apparmor_local_mariadbd}" 2>/dev/null; then
            {
                printf '\n# Added by LESta: mariadbd must read and write its own tenant datadir here.\n'
                printf '%s/ r,\n' "${TENANT_DATADIR}"
                printf '%s/** rwk,\n' "${TENANT_DATADIR}"
            } >> "${apparmor_local_mariadbd}" || fail_step "${EXIT_MUTATION_FAILURE}" apparmor_override_write_failed "${apparmor_local_mariadbd}" "failed to append the ${TENANT_DATADIR} allowance to ${apparmor_local_mariadbd}"
            add_change database.tenant.v1 apparmor_extended "${apparmor_local_mariadbd}" "granted mariadbd read/write access to ${TENANT_DATADIR} via Ubuntu's own local-override include point"
        fi

        if command -v apparmor_parser >/dev/null 2>&1; then
            apparmor_parser -r /etc/apparmor.d/usr.sbin.mariadbd || fail_step "${EXIT_MUTATION_FAILURE}" apparmor_reload_failed "${apparmor_local_mariadbd}" "apparmor_parser -r /etc/apparmor.d/usr.sbin.mariadbd failed"
            add_change database.tenant.v1 apparmor_reloaded "" "apparmor_parser -r reloaded the mariadbd profile with the ${TENANT_DATADIR} allowance"
        fi
    else
        log_info "AppArmor local override file for mariadbd not present (${apparmor_local_mariadbd}); assuming AppArmor is not enforcing mariadbd on this host, skipping"
    fi

    # --- enable + start the tenant instance --------------------------------
    systemctl daemon-reload || fail_step "${EXIT_MUTATION_FAILURE}" systemctl_daemon_reload_failed "" "systemctl daemon-reload failed"

    if ! out=$(systemctl enable --now mariadb@tenant.service 2>&1); then
        mariadb_fail_health "${EXIT_HEALTH_FAILURE}" mariadb_tenant_enable_failed "" "$(printf '%s' "${out}" | tr '\n' ' ')"
    fi
    add_change database.tenant.v1 enabled "" "systemctl enable --now mariadb@tenant.service succeeded (--defaults-group-suffix=.tenant, reading [mysqld.tenant])"

    mariadb_health_probe "${TENANT_SOCKET}" || mariadb_fail_health "${EXIT_HEALTH_FAILURE}" mariadb_health_check_failed "${TENANT_SOCKET}" "a real SELECT 1 round-trip over ${TENANT_SOCKET} did not succeed within 60s of enabling mariadb@tenant.service"
    add_change database.tenant.v1 healthy "" "a real SELECT 1 round-trip over ${TENANT_SOCKET} succeeded"

    # --- dedicated admin account + --defaults-extra-file -------------------
    #
    # Connects as the OS root user over the LOCAL SOCKET (never TCP,
    # regardless of whether mariadb-install-db happened to configure root
    # for unix_socket or normal password authentication -- socket auth as
    # the matching OS user works either way and is the same mechanism
    # `sudo mariadb` relies on everywhere). The admin account itself is
    # created for TCP access at '127.0.0.1' specifically (never 'localhost'
    # -- see agent/internal/capability/mariadb/exec.go's own grantHost doc
    # comment for why), matching the exact host every GRANT/REVOKE/CREATE
    # USER/ALTER USER/DROP USER statement the agent itself issues also
    # targets.
    # CREATE OR REPLACE USER, not CREATE USER IF NOT EXISTS: a fresh
    # admin_password is generated on every apply (this installer keeps no
    # state across runs to reuse a prior one), but IF NOT EXISTS is a no-op
    # against an already-existing user from a prior run -- the password
    # would never actually change server-side while
    # TENANT_ADMIN_DEFAULTS_FILE below is unconditionally rewritten with the
    # new one regardless, permanently desyncing the two after the second
    # apply. Confirmed directly via a real CI idempotent-rerun: the second
    # apply's own self-test failed with "Access denied for user
    # 'lesta_agent'@'localhost' (using password: YES)" -- the agent
    # authenticating with the freshly-rewritten (but never actually applied)
    # password. REPLACE resets the user (and, by extension, its grants) on
    # every run, which is correct here since the very next statement
    # unconditionally re-grants the exact same fixed privilege set anyway --
    # unlike tenant password rotation (see exec.go's own rotateDDL doc
    # comment), there is no pre-existing, independently-managed grant state
    # to preserve for this installer-owned admin account.
    admin_password=$(od -An -tx1 -N24 /dev/urandom | tr -d ' \n')

    if ! out=$(mariadb --socket="${TENANT_SOCKET}" -u root <<ADMINSQL 2>&1
CREATE OR REPLACE USER '${TENANT_ADMIN_USER}'@'127.0.0.1' IDENTIFIED BY '${admin_password}';
GRANT ALL PRIVILEGES ON *.* TO '${TENANT_ADMIN_USER}'@'127.0.0.1' WITH GRANT OPTION;
FLUSH PRIVILEGES;
ADMINSQL
    ); then
        add_error mariadb_admin_account_failed "$(printf '%s' "${out}" | tr '\n' ' ')" ""
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi
    add_change database.tenant.v1 installed "" "dedicated admin account ${TENANT_ADMIN_USER}@127.0.0.1 created with ALL PRIVILEGES ... WITH GRANT OPTION"

    cat > "${TENANT_ADMIN_DEFAULTS_FILE}.tmp" <<DEFAULTS
[client]
user=${TENANT_ADMIN_USER}
password=${admin_password}
DEFAULTS
    chmod 0600 "${TENANT_ADMIN_DEFAULTS_FILE}.tmp"
    chown root:root "${TENANT_ADMIN_DEFAULTS_FILE}.tmp" 2>/dev/null || true
    mv -f "${TENANT_ADMIN_DEFAULTS_FILE}.tmp" "${TENANT_ADMIN_DEFAULTS_FILE}"
    add_change database.tenant.v1 installed "${TENANT_ADMIN_DEFAULTS_FILE}" "--defaults-extra-file written, mode 0600 root:root, matching agent/cmd/lesta-agent/main.go's own mariadbProductionConfig().DefaultsExtraFile"

    checkpoint_write install_mariadb_tenant "${MANIFEST_DIGEST}"
    log_info "install_mariadb_tenant complete"
}

# --- phase 6: bootstrap_node_health --------------------------------------
#
# selftest_new_uuid/selftest_envelope/selftest_invoke_agent/
# selftest_status_from_output/run_node_health_selftest_delete live in
# lib/selftest.sh, shared with every other leaf-service installer's own
# self-test.

# run_node_health_selftest feeds a real `create` then `delete`
# OperationEnvelope to the just-placed agent binary, targeting the exact
# real production paths (the tenant instance install_mariadb_tenant just
# enabled and health-probed, and TENANT_ADMIN_DEFAULTS_FILE it just wrote).
# The resource is a throwaway database (lesta_0_selftest, account_id 0 is a
# reserved sentinel no real Account ever uses) that exists only for the
# duration of this function: created, verified applied, then deleted,
# leaving no residue for a control plane that never heard of this resource
# to reconcile.
run_node_health_selftest() {
    local resource_id create_idem create_corr delete_idem delete_corr selftest_password create_payload delete_payload envelope agent_out agent_status status_line

    resource_id=$(selftest_new_uuid)
    create_idem=$(selftest_new_uuid)
    create_corr=$(selftest_new_uuid)
    delete_idem=$(selftest_new_uuid)
    delete_corr=$(selftest_new_uuid)
    selftest_password=$(od -An -tx1 -N24 /dev/urandom | tr -d ' \n')

    create_payload=$(json_join_object \
        "$(json_kv_str "database_name" "lesta_0_selftest")" \
        "$(json_kv_str "database_user" "lesta_0_selftest")" \
        "$(json_kv_str "password" "${selftest_password}")" \
        "$(json_kv_raw "suspended" "false")")

    delete_payload=$(json_join_object \
        "$(json_kv_str "database_name" "lesta_0_selftest")" \
        "$(json_kv_str "database_user" "lesta_0_selftest")" \
        "$(json_kv_raw "suspended" "false")")

    envelope=$(selftest_envelope "${DATABASE_TENANT_CAPABILITY}" create "${resource_id}" "${create_idem}" "${create_corr}" 1 "${create_payload}")

    agent_status=0
    agent_out=$(selftest_invoke_agent "${envelope}") || agent_status=$?

    if [ "${agent_status}" -ne 0 ]; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_create_failed "${AGENT_BINARY_DEST}" "agent exited ${agent_status}: $(printf '%s' "${agent_out}" | tr '\n' ' ')"
    fi

    status_line=$(selftest_status_from_output "${agent_out}")
    if [ "${status_line}" != "applied" ]; then
        run_node_health_selftest_delete "${DATABASE_TENANT_CAPABILITY}" "${resource_id}" "${delete_payload}" "${delete_idem}" "${delete_corr}" || true
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_create_not_applied "${AGENT_BINARY_DEST}" "agent returned status=${status_line:-unknown} for create, expected applied: $(printf '%s' "${agent_out}" | tr '\n' ' ')"
    fi

    log_info "bootstrap_node_health self-test: create returned status=applied"

    if ! run_node_health_selftest_delete "${DATABASE_TENANT_CAPABILITY}" "${resource_id}" "${delete_payload}" "${delete_idem}" "${delete_corr}"; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_cleanup_failed "${TENANT_DATADIR}" "self-test create succeeded but the throwaway resource could not be deleted afterward"
    fi

    add_change database.tenant.v1 installed_structural_only "${AGENT_BINARY_DEST}" "self-test create-then-delete of a throwaway tenant database (lesta_0_selftest) against the real, just-installed tenant MariaDB instance returned status=applied both times; remote control-plane registration is not yet built, so database.tenant.v1 is structurally installed and health-checked but NOT YET control-plane-registered"
    log_info "bootstrap_node_health self-test: create+delete both returned status=applied"
}

bootstrap_node_health() {
    log_info "bootstrap_node_health: installing agent binary and running disposable self-test"

    agent_install_binary "${AGENT_BINARY_SRC}" "${NODE_HEALTH_MANIFEST}"

    run_node_health_selftest

    checkpoint_write bootstrap_node_health "${MANIFEST_DIGEST}"
    log_info "bootstrap_node_health complete"
}

# --- main -------------------------------------------------------------

main() {
    MANIFEST_DIGEST=$(compute_manifest_digest "${BASE_MANIFEST}" "${FIREWALL_MANIFEST}" "${NODE_HEALTH_MANIFEST}" "${MARIADB_MANIFEST}")

    parse_args "$@"
    validate_args

    if [ "${MODE}" = "version" ]; then
        emit_version_and_exit
    fi

    RUN_ID=$(run_generate_id)
    run_install_cleanup_trap

    # No mutation happens before this point (log_init itself would create a
    # directory, ensure_lesta_group would run groupadd), matching every
    # other installer's own ordering.
    log_info "starting install.sh mode=${MODE} run_id=${RUN_ID} installer_version=${SCRIPT_VERSION}"

    run_preflight

    if [ "${MODE}" = "dry-run" ]; then
        emit_dry_run_result_and_exit
    fi

    # --apply, and preflight passed: only now is any mutation permitted.
    ensure_lesta_group
    log_init
    log_info "preflight passed; beginning apply mutations"

    bootstrap_base
    bootstrap_firewall_baseline_mariadb
    checkpoint_write bootstrap_firewall_baseline "${MANIFEST_DIGEST}"
    ensure_mariadb_package_installed
    install_mariadb_control_plane
    install_mariadb_tenant
    bootstrap_node_health

    checkpoint_remove
    release_write "${RELEASE_ID}" "${MANIFEST_DIGEST}"

    emit_apply_success_and_exit
}

main "$@"
