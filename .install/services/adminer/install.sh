#!/bin/sh
# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/services/adminer/install.sh
#
# Bootstrap installer for the tools.adminer.v1 capability: takes a node
# that already has web.php-fpm.v1 and web.nginx.v1 bootstrapped to a state
# where a single, fixed, node-wide Adminer instance is installed behind its
# own dedicated PHP-FPM pool, per .install/INSTALLER-CONTRACT.md.
#
# Unlike php-fpm/nginx/mariadb's own OS-level package installs, there is no
# Go dispatch package for this capability at all (see
# agent/cmd/lesta-agent/main.go's own adminerCapability doc comment): this
# script IS the entire install step. Nothing is ever created/updated/
# deleted for tools.adminer.v1 via the agent-mediated OperationEnvelope/
# ProvisioningOperation pipeline afterward, so there is no sudoers rule for
# a live daemon to invoke later -- every mutation below runs once, now,
# as root, under this installer's own apply.
#
# Reuses php-fpm's own already-installed PHP 8.3 package (Ubuntu 24.04's
# own native version, always present per php-fpm/install.sh's own
# PHP_VERSIONS list) rather than installing anything new: this installer
# only ever adds one more pool.d fragment under the php-fpm capability's
# own owned_roots, plus a dedicated system user/group, a vendored-file
# deployment directory, and a fixed node-wide socket -- never a tenant's
# own per-domain pool.
set -eu

# --- constants -------------------------------------------------------------

SCRIPT_VERSION="1.0.0"
RELEASE_ID="2026.10.04"
ADMINER_CAPABILITY="tools.adminer.v1"

# Fixed PHP version for the Adminer pool: never per-tenant-selectable (see
# this installer's own manifest.json doc, and php-fpm/install.sh's own
# PHP_VERSIONS list, which guarantees 8.3 -- Ubuntu 24.04's own native
# package -- is always installed on any node that already has
# web.php-fpm.v1 bootstrapped).
ADMINER_PHP_VERSION="8.3"

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

BASE_MANIFEST="${INSTALL_ROOT}/base/manifest.json"
NODE_HEALTH_MANIFEST="${INSTALL_ROOT}/services/node-health/manifest.json"
ADMINER_MANIFEST="${INSTALL_ROOT}/services/adminer/manifest.json"

VENDOR_DIR="${REPO_ROOT}/.install/services/adminer/vendor"
ADMINER_SRC="${VENDOR_DIR}/adminer.php"
LOGIN_PLUGIN_SRC="${VENDOR_DIR}/adminer-lesta-login.php"

# Fixed production paths. Keep in lockstep with
# agent/internal/capability/nginx/templates/php.conf.tmpl's own
# /__lesta-adminer__ location block (fastcgi_param SCRIPT_FILENAME) and
# .install/services/adminer/vendor/adminer-lesta-login.php's own doc
# comment (control-plane-url.txt lives alongside it).
DEPLOY_DIR="/var/www/lesta-adminer"
ADMINER_DEST="${DEPLOY_DIR}/adminer.php"
LOGIN_PLUGIN_DEST="${DEPLOY_DIR}/adminer-lesta-login.php"
CONTROL_PLANE_URL_FILE="${DEPLOY_DIR}/control-plane-url.txt"
DAEMON_CONFIG_PATH="/etc/lesta/agent/daemon-config.json"

PHP_POOL_BASE_DIR="/etc/php"
ADMINER_POOL_CONF="${PHP_POOL_BASE_DIR}/${ADMINER_PHP_VERSION}/fpm/pool.d/lesta-adminer.conf"
ADMINER_SOCKET_DIR="/run/lesta-adminer"
ADMINER_SOCKET_PATH="${ADMINER_SOCKET_DIR}/adminer.sock"
ADMINER_STATE_ROOT="/var/lib/lesta/adminer"

# CHECKPOINT_PATH/RELEASE_PATH: this installer's own paths, distinct from
# every other leaf-service installer's own (see lib/checkpoint.sh's own top
# comment).
export CHECKPOINT_PATH="/var/lib/lesta/install/adminer.checkpoint"
export RELEASE_PATH="/etc/lesta/adminer-release"

# --- globals (all pre-declared for `set -u` safety) -------------------------

MODE=""
YES=0
RUN_ID=""
MANIFEST_DIGEST=""
CHANGES=""
ERRORS=""

# --- usage / argument parsing ----------------------------------------------

usage() {
    cat <<'USAGE' >&2
Usage: install.sh --dry-run|--apply|--version [--yes] [--help]

  --dry-run   Run preflight and report what would change. No mutation.
  --apply     Apply the installer. Requires --yes.
  --version   Print installer version and exit.
  --yes       Required with --apply: non-interactive confirmation.
  --help      Print this message.
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

    items=$(manifest_extract_array "${ADMINER_MANIFEST}" "depends_on")

    while IFS= read -r item; do
        [ -n "${item}" ] || continue
        lines=$(append_line "${lines}" "$(json_str "${item}")")
    done <<ITEMS
${items}
ITEMS

    json_array_from_lines "${lines}"
}

emit_result_and_exit() {
    local status="$1" exit_code="$2" changes_json errors_json required_json provided_json result

    changes_json=$(json_array_from_lines "${CHANGES}")
    errors_json=$(json_array_from_lines "${ERRORS}")
    required_json=$(manifest_capabilities_required_json)
    provided_json=$(json_array_from_lines "$(json_str "${ADMINER_CAPABILITY}")")

    result=$(json_join_object \
        "$(json_kv_str "schema_version" "1")" \
        "$(json_kv_str "installer" "lesta-bootstrap")" \
        "$(json_kv_str "service" "adminer")" \
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

emit_dry_run_result_and_exit() {
    local install_state
    install_state=$(preflight_classify_install_state)

    add_change base.os.v1 would_ensure /etc/lesta "install-state classification: ${install_state}"
    add_change "${ADMINER_CAPABILITY}" would_ensure /etc/passwd "a dedicated system user+group lesta-adminer would be created (--system --no-create-home, no login shell)"
    add_change "${ADMINER_CAPABILITY}" would_install "${DEPLOY_DIR}" "${DEPLOY_DIR} would be created (root:lesta-adminer, mode 0750); adminer.php and adminer-lesta-login.php would be checksum-verified against the manifest and copied in, mode 0640 root:lesta-adminer"
    add_change "${ADMINER_CAPABILITY}" would_write "${CONTROL_PLANE_URL_FILE}" "the control plane base URL would be parsed from ${DAEMON_CONFIG_PATH}'s own control_plane_url field and written here, mode 0644 root:lesta-adminer"
    add_change "${ADMINER_CAPABILITY}" would_install "${ADMINER_POOL_CONF}" "a dedicated static PHP ${ADMINER_PHP_VERSION}-FPM pool (pm.max_children=3) would be written, listening on ${ADMINER_SOCKET_PATH}, open_basedir restricted to ${DEPLOY_DIR}:/tmp; php${ADMINER_PHP_VERSION}-fpm would be reloaded"
    add_change "${ADMINER_CAPABILITY}" would_ensure "${ADMINER_STATE_ROOT}" "presence-marker directory would be created, mode 0750 root:lesta"

    emit_result_and_exit would_change "${EXIT_OK}"
}

emit_apply_success_and_exit() {
    log_info "install.sh apply completed successfully"
    emit_result_and_exit applied "${EXIT_OK}"
}

# --- preflight orchestration --------------------------------------------

# preflight_check_dependency_package <package> <human_capability_label>
# Real, direct dpkg/systemd check (the same mechanism
# .install/scripts/install-selected.sh's own resolve_order and --prune's
# own uninstall_service_present rely on) that a dependency this installer
# itself never installs is genuinely present on this node already, rather
# than trusting manifest depends_on declarations alone (those only drive
# the combined installer's own ordering/graph validation, not a live
# node's real state).
preflight_check_dependency_package() {
    local package="$1" label="$2"

    if ! dpkg-query -W -f='${Status}' "${package}" 2>/dev/null | grep -q '^install ok installed$'; then
        add_error dependency_not_installed "${label} is not installed on this node (dpkg-query found no installed ${package}); bootstrap it first (its own install.sh --apply)" "${package}"
        return 1
    fi

    return 0
}

# preflight_check_adminer_identity
# Fails when a lesta-adminer user already exists with a home or shell that
# does not match what bootstrap_adminer_identity would create, so this
# installer never silently adopts an unrelated pre-existing account.
# Mirrors preflight_check_lesta_identity's own shape exactly.
preflight_check_adminer_identity() {
    local expected_home="/var/lib/lesta" expected_shell="/usr/sbin/nologin" home shell

    if getent passwd lesta-adminer >/dev/null 2>&1; then
        home=$(getent passwd lesta-adminer | awk -F: '{print $6}')
        shell=$(getent passwd lesta-adminer | awk -F: '{print $7}')

        if [ "${home}" != "${expected_home}" ] || [ "${shell}" != "${expected_shell}" ]; then
            add_error conflicting_identity "an existing lesta-adminer user has home=${home} shell=${shell}, which does not match what this installer would create (home=${expected_home} shell=${expected_shell})" "/etc/passwd"
            return 1
        fi
    fi

    return 0
}

# preflight_check_daemon_config_present
# tools.adminer.v1 needs the control plane's own base URL, which only
# exists once this node has already been enrolled
# (agent-daemon/install.sh's own daemon_write_config). A missing file here
# means enrollment has not happened yet -- a real precondition, reported at
# preflight (so --dry-run surfaces it too), not silently skipped.
preflight_check_daemon_config_present() {
    if [ ! -f "${DAEMON_CONFIG_PATH}" ]; then
        add_error daemon_config_missing "${DAEMON_CONFIG_PATH} does not exist; this node has not completed agent-daemon enrollment yet (run .install/services/agent-daemon/install.sh --apply first), so tools.adminer.v1 has no control plane URL to write into control-plane-url.txt" "${DAEMON_CONFIG_PATH}"
        return 1
    fi

    return 0
}

run_preflight() {
    local arch os_version_id failed=0

    arch=$(uname -m)
    os_version_id=$(preflight_os_release_field VERSION_ID)

    case "${os_version_id}" in
        24.04 | 26.04) ;;
        *)
            add_error unsupported_ubuntu "detected Ubuntu ${os_version_id:-unknown}; supported releases are 24.04, 26.04" ""
            emit_result_and_exit failed "${EXIT_UNSUPPORTED_PLATFORM}"
            ;;
    esac

    case "${arch}" in
        x86_64) ;;
        *)
            add_error unsupported_architecture "detected architecture ${arch}; only amd64 is supported" ""
            emit_result_and_exit failed "${EXIT_UNSUPPORTED_PLATFORM}"
            ;;
    esac

    log_info "platform ok: ubuntu ${os_version_id} ${arch} kernel=$(uname -r)"

    # Deliberately excludes /run: it's tmpfs, sized as a fraction of RAM
    # rather than real disk, and this capability only ever needs a few KB
    # there for one unix socket -- php-fpm/install.sh's own capacity loop
    # (which also creates /run/lesta-php) excludes it for the identical
    # reason. Checking it against the same "need 1 GiB free" threshold
    # real disk-backed directories use would fail on every real node
    # (confirmed directly: a real lesta-cp-01 /run is a 392M tmpfs).
    for dir in /etc /var/lib /var/www; do
        preflight_check_capacity "${dir}" || failed=1
    done

    # No port-free preflight check at all: this capability's own manifest
    # declares ports: [], since the Adminer pool listens on a unix socket
    # only, dialed by nginx's own already-bootstrapped vhost, never a
    # separate network listener of its own.
    preflight_check_dependency_package "php${ADMINER_PHP_VERSION}-fpm" "web.php-fpm.v1 (PHP ${ADMINER_PHP_VERSION}-FPM)" || failed=1
    preflight_check_dependency_package "nginx" "web.nginx.v1" || failed=1
    preflight_check_adminer_identity || failed=1
    preflight_check_daemon_config_present || failed=1

    if [ ! -f "${ADMINER_SRC}" ] || [ ! -f "${LOGIN_PLUGIN_SRC}" ]; then
        add_error artifact_missing "${VENDOR_DIR} is missing adminer.php and/or adminer-lesta-login.php" "${VENDOR_DIR}"
        failed=1
    fi

    if [ "${failed}" -ne 0 ]; then
        emit_result_and_exit failed "${EXIT_PREFLIGHT_CONFLICT}"
    fi

    log_info "preflight passed"
}

# --- phase 1: bootstrap_adminer_identity ---------------------------------

ensure_lesta_group() {
    getent group lesta >/dev/null 2>&1 || groupadd --system lesta
}

bootstrap_adminer_identity() {
    log_info "bootstrap_adminer_identity: ensuring lesta-adminer system user/group"

    if ! getent group lesta-adminer >/dev/null 2>&1; then
        groupadd --system lesta-adminer || fail_step "${EXIT_MUTATION_FAILURE}" groupadd_failed /etc/group "failed to create group lesta-adminer"
        add_change "${ADMINER_CAPABILITY}" created /etc/group "created dedicated system group lesta-adminer"
    else
        add_change "${ADMINER_CAPABILITY}" verified /etc/group "group lesta-adminer already exists"
    fi

    if ! getent passwd lesta-adminer >/dev/null 2>&1; then
        useradd --system --gid lesta-adminer --home-dir /var/lib/lesta --no-create-home \
            --shell /usr/sbin/nologin --comment "LESta Adminer pool identity" lesta-adminer \
            || fail_step "${EXIT_MUTATION_FAILURE}" useradd_failed /etc/passwd "failed to create system user lesta-adminer"
        add_change "${ADMINER_CAPABILITY}" created /etc/passwd "created dedicated system user lesta-adminer, group lesta-adminer, no home, no login shell"
    else
        add_change "${ADMINER_CAPABILITY}" verified /etc/passwd "system user lesta-adminer already exists"
    fi

    checkpoint_write bootstrap_adminer_identity "${MANIFEST_DIGEST}"
    log_info "bootstrap_adminer_identity complete"
}

# --- phase 2: deploy_adminer_files ---------------------------------------

deploy_one_vendored_file() {
    local src="$1" dest="$2" artifact_name="$3" expected_sha256 actual_sha256

    expected_sha256=$(manifest_artifact_sha256 "${ADMINER_MANIFEST}" "${artifact_name}")
    if [ -z "${expected_sha256}" ]; then
        fail_step "${EXIT_VERIFICATION_FAILURE}" artifact_not_declared "${ADMINER_MANIFEST}" "adminer manifest has no ${artifact_name} artifacts[] entry"
    fi

    verify_sha256 "${src}" "${expected_sha256}" \
        || fail_step "${EXIT_VERIFICATION_FAILURE}" checksum_mismatch "${src}" "${artifact_name} sha256 does not match adminer manifest's artifacts[] entry"

    if [ -f "${dest}" ]; then
        actual_sha256=$(compute_sha256 "${dest}")
        if [ "${actual_sha256}" = "${expected_sha256}" ]; then
            add_change "${ADMINER_CAPABILITY}" verified "${dest}" "${artifact_name} already at the target sha256 (${expected_sha256}); no-op"
            return 0
        fi
    fi

    cp "${src}" "${dest}.tmp" || fail_step "${EXIT_MUTATION_FAILURE}" copy_failed "${dest}" "failed to copy ${artifact_name} into place"
    chmod 0640 "${dest}.tmp"
    chown root:lesta-adminer "${dest}.tmp" || fail_step "${EXIT_MUTATION_FAILURE}" chown_failed "${dest}" "failed to chown ${artifact_name} to root:lesta-adminer"
    mv -f "${dest}.tmp" "${dest}" || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${dest}" "failed to activate ${dest}"
    add_change "${ADMINER_CAPABILITY}" installed "${dest}" "${artifact_name} copied from the repo-vendored source, sha256 verified against the manifest (${expected_sha256}), mode 0640 root:lesta-adminer"
}

# parse_control_plane_url <daemon_config_path> -> prints the control_plane_url
# field's value, or nothing. A small, single-field sed extraction (matching
# this project's own established "simple single-field extraction from a
# small JSON file" convention -- see lib/preflight.sh's own
# manifest_extract_array/manifest_artifact_sha256), since this file is
# first-party-written (agent-daemon/install.sh's own daemon_write_config)
# with one value per line, never arbitrary nested JSON a sed pass could
# misparse.
parse_control_plane_url() {
    local daemon_config="$1"

    sed -n 's/.*"control_plane_url"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' "${daemon_config}" | head -1
}

write_control_plane_url_file() {
    local control_plane_url

    control_plane_url=$(parse_control_plane_url "${DAEMON_CONFIG_PATH}")

    if [ -z "${control_plane_url}" ]; then
        fail_step "${EXIT_PREFLIGHT_CONFLICT}" control_plane_url_missing "${DAEMON_CONFIG_PATH}" "${DAEMON_CONFIG_PATH} exists but its own control_plane_url field is missing or empty; this node's agent-daemon enrollment appears incomplete"
    fi

    printf '%s\n' "${control_plane_url}" > "${CONTROL_PLANE_URL_FILE}.tmp"
    chmod 0644 "${CONTROL_PLANE_URL_FILE}.tmp"
    chown root:lesta-adminer "${CONTROL_PLANE_URL_FILE}.tmp" \
        || fail_step "${EXIT_MUTATION_FAILURE}" chown_failed "${CONTROL_PLANE_URL_FILE}" "failed to chown control-plane-url.txt to root:lesta-adminer"
    mv -f "${CONTROL_PLANE_URL_FILE}.tmp" "${CONTROL_PLANE_URL_FILE}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${CONTROL_PLANE_URL_FILE}" "failed to activate ${CONTROL_PLANE_URL_FILE}"
    add_change "${ADMINER_CAPABILITY}" installed "${CONTROL_PLANE_URL_FILE}" "control plane base URL (${control_plane_url}) parsed from ${DAEMON_CONFIG_PATH} and written here, mode 0644 root:lesta-adminer"
}

deploy_adminer_files() {
    log_info "deploy_adminer_files: creating ${DEPLOY_DIR} and installing vendored files"

    ensure_lesta_group

    install -d -m 0750 -o root -g lesta-adminer "${DEPLOY_DIR}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${DEPLOY_DIR}" "failed to create ${DEPLOY_DIR}"
    add_change "${ADMINER_CAPABILITY}" ensured "${DEPLOY_DIR}" "deploy directory present, mode 0750 root:lesta-adminer"

    deploy_one_vendored_file "${ADMINER_SRC}" "${ADMINER_DEST}" "adminer.php"
    deploy_one_vendored_file "${LOGIN_PLUGIN_SRC}" "${LOGIN_PLUGIN_DEST}" "adminer-lesta-login.php"
    write_control_plane_url_file

    checkpoint_write deploy_adminer_files "${MANIFEST_DIGEST}"
    log_info "deploy_adminer_files complete"
}

# --- phase 3: install_adminer_pool ---------------------------------------

# Disable_functions list copied verbatim from
# agent/internal/capability/phpfpm/templates/pool.conf.tmpl's own tenant
# pool template: defense in depth, never the primary boundary -- the
# primary boundary is this pool's own dedicated lesta-adminer OS uid/gid,
# per Web Application Hosting Threat Model and Isolation Design.md's own
# Boundary 3.
install_adminer_pool() {
    log_info "install_adminer_pool: writing dedicated PHP ${ADMINER_PHP_VERSION}-FPM pool"

    install -d -m 0750 -o lesta-adminer -g www-data "${ADMINER_SOCKET_DIR}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${ADMINER_SOCKET_DIR}" "failed to create ${ADMINER_SOCKET_DIR}"
    add_change "${ADMINER_CAPABILITY}" ensured "${ADMINER_SOCKET_DIR}" "socket directory present, mode 0750 lesta-adminer:www-data (nginx, running as www-data, must be able to traverse in and dial the socket)"

    # /run is tmpfs, recreated empty on every boot -- a systemd-tmpfiles
    # rule, not a one-time `install -d`, is what makes this durable across
    # reboots (mirrors php-fpm/install.sh's own /run/lesta-php rule).
    cat > /etc/tmpfiles.d/lesta-adminer.conf.tmp <<TMPFILES
d ${ADMINER_SOCKET_DIR} 0750 lesta-adminer www-data -
TMPFILES
    mv -f /etc/tmpfiles.d/lesta-adminer.conf.tmp /etc/tmpfiles.d/lesta-adminer.conf
    systemd-tmpfiles --create /etc/tmpfiles.d/lesta-adminer.conf >/dev/null 2>&1 || true
    add_change "${ADMINER_CAPABILITY}" installed /etc/tmpfiles.d/lesta-adminer.conf "systemd-tmpfiles rule written so ${ADMINER_SOCKET_DIR} survives a reboot (/run is tmpfs)"

    cat > "${ADMINER_POOL_CONF}.tmp" <<POOLCONF
; Managed by LESta's own tools.adminer.v1 capability. Do not edit by hand --
; a future apply of .install/services/adminer/install.sh will overwrite it
; unconditionally.
[lesta-adminer]
user = lesta-adminer
group = lesta-adminer
listen = ${ADMINER_SOCKET_PATH}
listen.owner = lesta-adminer
listen.group = www-data
listen.mode = 0660

; Static, not dynamic: this is one fixed, node-wide, low-traffic admin
; tool, never a per-tenant pool sized for real traffic.
pm = static
pm.max_children = 3

; Defense in depth, never the primary boundary -- the primary boundary is
; this pool's own dedicated lesta-adminer OS uid/gid, per Web Application
; Hosting Threat Model and Isolation Design.md's own Boundary 3.
; open_basedir is scoped to ${DEPLOY_DIR} only, never a tenant docroot:
; this is the real isolation boundary between Adminer's own PHP process
; and every tenant's own files on this node.
php_admin_value[open_basedir] = ${DEPLOY_DIR}:/tmp
php_admin_value[disable_functions] = exec,shell_exec,system,popen,proc_open,pcntl_exec,pcntl_fork,pcntl_signal

; Deliberately ON, unlike a tenant's own pool: adminer-lesta-login.php's
; own credential handoff (lesta_adminer_redeem_token()) makes exactly one
; outbound HTTPS call to this node's own known, fixed control plane URL via
; file_get_contents() -- the only outbound network capability this pool
; ever needs, and a narrow, justified exception to the tenant-pool default
; precisely because that default (preventing arbitrary tenant PHP from
; reaching arbitrary internal/external URLs, a real SSRF boundary) does not
; apply here: this pool never runs tenant-authored code at all. Found the
; hard way on a real node: file_get_contents() over https:// silently
; returns false with allow_url_fopen off, surfacing only as "could not
; reach the control plane" with no further detail by design (see that
; function's own doc comment on why it never reveals more).
php_admin_flag[allow_url_fopen] = on
POOLCONF
    mv -f "${ADMINER_POOL_CONF}.tmp" "${ADMINER_POOL_CONF}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${ADMINER_POOL_CONF}" "failed to activate ${ADMINER_POOL_CONF}"
    add_change "${ADMINER_CAPABILITY}" installed "${ADMINER_POOL_CONF}" "dedicated static pool written: pm.max_children=3, listen=${ADMINER_SOCKET_PATH}, open_basedir=${DEPLOY_DIR}:/tmp"

    if ! "/usr/sbin/php-fpm${ADMINER_PHP_VERSION}" -t >/dev/null 2>&1; then
        fail_step "${EXIT_HEALTH_FAILURE}" pool_config_invalid "${ADMINER_POOL_CONF}" "php-fpm${ADMINER_PHP_VERSION} -t rejected the rendered pool config"
    fi

    systemctl reload "php${ADMINER_PHP_VERSION}-fpm" \
        || fail_step "${EXIT_HEALTH_FAILURE}" systemctl_reload_failed "" "systemctl reload php${ADMINER_PHP_VERSION}-fpm failed"
    add_change "${ADMINER_CAPABILITY}" reloaded "" "systemctl reload php${ADMINER_PHP_VERSION}-fpm succeeded"

    checkpoint_write install_adminer_pool "${MANIFEST_DIGEST}"
    log_info "install_adminer_pool complete"
}

# --- phase 4: run_adminer_selftest ---------------------------------------

# This capability is a single, static, always-on, node-wide pool, never a
# per-resource lifecycle the way nginx/php-fpm's own pools are (there is
# nothing to create-then-delete here): health is proven the same way the
# pool config itself was already validated above (php-fpm -t), plus
# confirming the real unix socket actually appears after the reload --
# the closest equivalent this capability has to another installer's own
# create-then-verify round trip.
run_adminer_selftest() {
    local waited=0

    while [ ! -S "${ADMINER_SOCKET_PATH}" ] && [ "${waited}" -lt 10 ]; do
        sleep 1
        waited=$((waited + 1))
    done

    if [ ! -S "${ADMINER_SOCKET_PATH}" ]; then
        fail_step "${EXIT_HEALTH_FAILURE}" selftest_socket_missing "${ADMINER_SOCKET_PATH}" "php${ADMINER_PHP_VERSION}-fpm was reloaded but no real unix socket appeared at ${ADMINER_SOCKET_PATH} within 10s"
    fi

    add_change "${ADMINER_CAPABILITY}" verified "${ADMINER_SOCKET_PATH}" "php-fpm${ADMINER_PHP_VERSION} -t passed and the real unix socket exists and is live after reload"
    log_info "run_adminer_selftest: ${ADMINER_SOCKET_PATH} is live"
}

# --- phase 5: bootstrap_state_root ---------------------------------------

# /var/lib/lesta/adminer is a pure presence marker (the same mechanism
# agent/cmd/lesta-agent/main.go's own CapabilityStateRoots map uses to ever
# report tools.adminer.v1 as Running): no sudoers file is needed for it,
# because every mutation this capability ever makes happens right here,
# during this installer's own --apply, already running as root -- there is
# no live-daemon-invoked root action afterward, since there is no Go
# dispatch package for this capability at all (contrast with
# web.php-fpm.v1's own sudoers rule, needed because the real
# lesta-agent-daemon process, running unprivileged, must later invoke
# `php-fpm<version> -t` and `systemctl reload` itself, on demand, for every
# future tenant pool create/update).
bootstrap_state_root() {
    log_info "bootstrap_state_root: creating ${ADMINER_STATE_ROOT}"

    ensure_lesta_group

    install -d -m 0750 -o root -g lesta "${ADMINER_STATE_ROOT}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${ADMINER_STATE_ROOT}" "failed to create ${ADMINER_STATE_ROOT}"
    add_change "${ADMINER_CAPABILITY}" ensured "${ADMINER_STATE_ROOT}" "presence-marker directory present, mode 0750 root:lesta"

    checkpoint_write bootstrap_state_root "${MANIFEST_DIGEST}"
    log_info "bootstrap_state_root complete"
}

# --- main -------------------------------------------------------------

main() {
    MANIFEST_DIGEST=$(compute_manifest_digest "${BASE_MANIFEST}" "${NODE_HEALTH_MANIFEST}" "${ADMINER_MANIFEST}")

    parse_args "$@"
    validate_args

    if [ "${MODE}" = "version" ]; then
        emit_version_and_exit
    fi

    RUN_ID=$(run_generate_id)
    run_install_cleanup_trap

    log_info "starting install.sh mode=${MODE} run_id=${RUN_ID} installer_version=${SCRIPT_VERSION}"

    run_preflight

    if [ "${MODE}" = "dry-run" ]; then
        emit_dry_run_result_and_exit
    fi

    ensure_lesta_group
    log_init
    log_info "preflight passed; beginning apply mutations"

    bootstrap_adminer_identity
    deploy_adminer_files
    install_adminer_pool
    run_adminer_selftest
    bootstrap_state_root

    checkpoint_remove
    release_write "${RELEASE_ID}" "${MANIFEST_DIGEST}"

    emit_apply_success_and_exit
}

main "$@"
