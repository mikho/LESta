#!/bin/sh
# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/services/webmail/install.sh
#
# Bootstrap installer for the mail.webmail.v1 capability: takes a node that
# already has mail.smtp-imap.v1, web.php-fpm.v1 and web.nginx.v1 bootstrapped
# to a state where a single, fixed, node-wide Roundcube instance is installed
# behind its own dedicated PHP-FPM pool, per .install/INSTALLER-CONTRACT.md.
#
# Same shape as .install/services/adminer/install.sh: there is no Go dispatch
# package for this capability (see agent/cmd/lesta-agent/main.go's own
# webmailCapability doc comment), so this script IS the entire install step
# and every mutation below runs once, now, as root. nginx reaches the pool
# only through the node's own mail hostname domain's rendered vhost
# (web.nginx.v1's WebmailSocket field selects webmail.conf.tmpl), never a
# separate system vhost of its own.
#
# Unlike Adminer, Roundcube also needs a few PHP extensions php-fpm's own
# installer never adds (intl, sqlite3, mbstring, xml, zip), a persistent
# SQLite database, and a per-node encryption key (des_key), so its state
# root /var/lib/lesta/webmail is real state, not only a presence marker.
set -eu

# --- constants -------------------------------------------------------------

SCRIPT_VERSION="1.0.0"
RELEASE_ID="2026.10.05"
WEBMAIL_CAPABILITY="mail.webmail.v1"

# Fixed PHP version for the webmail pool, the same reasoning as Adminer's:
# php-fpm/install.sh's own PHP_VERSIONS list guarantees 8.3 (Ubuntu 24.04's
# own native package) on any node that already has web.php-fpm.v1.
WEBMAIL_PHP_VERSION="8.3"

# php8.3-cli is listed explicitly even though php8.3-fpm normally pulls it in:
# init_roundcube_db below runs Roundcube's own bin/initdb.sh through it.
WEBMAIL_PHP_PACKAGES="php${WEBMAIL_PHP_VERSION}-cli php${WEBMAIL_PHP_VERSION}-intl php${WEBMAIL_PHP_VERSION}-sqlite3 php${WEBMAIL_PHP_VERSION}-mbstring php${WEBMAIL_PHP_VERSION}-xml php${WEBMAIL_PHP_VERSION}-zip"

ROUNDCUBE_VERSION="1.7.4"
ROUNDCUBE_ARTIFACT="roundcubemail-${ROUNDCUBE_VERSION}-complete.tar.gz"
ROUNDCUBE_TOP_DIR="roundcubemail-${ROUNDCUBE_VERSION}"
PLUGIN_ARTIFACT="lesta_autologin.php"

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
WEBMAIL_MANIFEST="${INSTALL_ROOT}/services/webmail/manifest.json"

VENDOR_DIR="${REPO_ROOT}/.install/services/webmail/vendor"
ROUNDCUBE_TARBALL_SRC="${VENDOR_DIR}/${ROUNDCUBE_ARTIFACT}"
PLUGIN_SRC="${VENDOR_DIR}/lesta_autologin/${PLUGIN_ARTIFACT}"

# Fixed production paths. DEPLOY_DIR/public_html is the root
# agent/internal/capability/nginx/templates/webmail.conf.tmpl serves, and
# WEBMAIL_SOCKET_PATH is the value App\Models\WebDomain::resolveWebmailSocket()
# sends as webmail_socket: keep all three in lockstep.
DEPLOY_DIR="/var/www/lesta-webmail"
DEPLOY_STAGING_DIR="${DEPLOY_DIR}.staging"
DEPLOY_PREVIOUS_DIR="${DEPLOY_DIR}.previous"
PLUGIN_DEST_DIR="${DEPLOY_DIR}/plugins/lesta_autologin"
PLUGIN_DEST="${PLUGIN_DEST_DIR}/${PLUGIN_ARTIFACT}"
CONTROL_PLANE_URL_FILE="${PLUGIN_DEST_DIR}/control-plane-url.txt"
ROUNDCUBE_CONFIG="${DEPLOY_DIR}/config/config.inc.php"
DAEMON_CONFIG_PATH="/etc/lesta/agent/daemon-config.json"
ACME_CERTS_ROOT="/var/lib/lesta/acme/certs"

PHP_POOL_BASE_DIR="/etc/php"
WEBMAIL_POOL_CONF="${PHP_POOL_BASE_DIR}/${WEBMAIL_PHP_VERSION}/fpm/pool.d/lesta-webmail.conf"
WEBMAIL_SOCKET_DIR="/run/lesta-webmail"
WEBMAIL_SOCKET_PATH="${WEBMAIL_SOCKET_DIR}/webmail.sock"

WEBMAIL_STATE_ROOT="/var/lib/lesta/webmail"
DES_KEY_FILE="${WEBMAIL_STATE_ROOT}/des_key"
DEPLOYED_VERSION_FILE="${WEBMAIL_STATE_ROOT}/deployed-version"
ROUNDCUBE_DB="${WEBMAIL_STATE_ROOT}/roundcube.db"

# CHECKPOINT_PATH/RELEASE_PATH: this installer's own paths, distinct from
# every other leaf-service installer's own (see lib/checkpoint.sh's own top
# comment).
export CHECKPOINT_PATH="/var/lib/lesta/install/webmail.checkpoint"
export RELEASE_PATH="/etc/lesta/webmail-release"

# --- globals (all pre-declared for `set -u` safety) -------------------------

MODE=""
YES=0
MAIL_HOSTNAME=""
RUN_ID=""
MANIFEST_DIGEST=""
CHANGES=""
ERRORS=""

# --- usage / argument parsing ----------------------------------------------

usage() {
    cat <<'USAGE' >&2
Usage: install.sh --dry-run|--apply|--version [--mail-hostname <fqdn>] [--yes] [--help]

  --dry-run                Run preflight and report what would change. No mutation.
  --apply                  Apply the installer. Requires --yes and --mail-hostname.
  --version                Print installer version and exit.
  --mail-hostname <fqdn>   Required with --dry-run/--apply. The same hostname
                           mail/install.sh was applied with: Roundcube logs in
                           to Dovecot at ssl://<fqdn>:993 and sends through
                           Exim at tls://<fqdn>:587, both verified against
                           the certificate at
                           /var/lib/lesta/acme/certs/<fqdn>/{fullchain,
                           privkey}.pem, which must already exist.
  --yes                    Required with --apply: non-interactive confirmation.
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
            --mail-hostname)
                [ "$#" -ge 2 ] || fail_invocation "--mail-hostname requires a value"
                MAIL_HOSTNAME="$2"
                shift 2
                ;;
            --mail-hostname=*)
                MAIL_HOSTNAME="${1#--mail-hostname=}"
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

# Identical to mail/install.sh's own validate_mail_hostname: the value is
# embedded in config.inc.php (imap_host/smtp_host) and in the certificate
# path below, so it must be a real, syntactically valid hostname and nothing
# else (no quotes, slashes or whitespace can ever reach either).
validate_mail_hostname() {
    case "${MAIL_HOSTNAME}" in
        '') return 1 ;;
    esac

    printf '%s' "${MAIL_HOSTNAME}" | grep -Eq '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$'
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

    if ! validate_mail_hostname; then
        fail_invocation "--mail-hostname <fqdn> is required and must be a valid hostname (got: '${MAIL_HOSTNAME}')"
    fi
}

# --- result accumulation / emission -----------------------------------------

manifest_capabilities_required_json() {
    local items item lines=""

    items=$(manifest_extract_array "${WEBMAIL_MANIFEST}" "depends_on")

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
    provided_json=$(json_array_from_lines "$(json_str "${WEBMAIL_CAPABILITY}")")

    result=$(json_join_object \
        "$(json_kv_str "schema_version" "1")" \
        "$(json_kv_str "installer" "lesta-bootstrap")" \
        "$(json_kv_str "service" "webmail")" \
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

# missing_php_packages -> every package in WEBMAIL_PHP_PACKAGES not yet
# installed, space-separated (empty when all are present).
missing_php_packages() {
    local pkg missing=""

    for pkg in ${WEBMAIL_PHP_PACKAGES}; do
        if ! dpkg-query -W -f='${Status}' "${pkg}" 2>/dev/null | grep -q '^install ok installed$'; then
            missing="${missing} ${pkg}"
        fi
    done

    printf '%s' "${missing# }"
}

emit_dry_run_result_and_exit() {
    local install_state missing
    install_state=$(preflight_classify_install_state)
    missing=$(missing_php_packages)

    add_change base.os.v1 would_ensure /etc/lesta "install-state classification: ${install_state}"
    add_change "${WEBMAIL_CAPABILITY}" would_ensure /etc/passwd "a dedicated system user+group lesta-webmail would be created (--system --no-create-home, no login shell)"
    if [ -n "${missing}" ]; then
        add_change "${WEBMAIL_CAPABILITY}" would_install "" "apt-get install -y ${missing} (Roundcube's own PHP extension requirements)"
    else
        add_change "${WEBMAIL_CAPABILITY}" would_verify "" "every required PHP package is already installed (${WEBMAIL_PHP_PACKAGES}); no apt-get run"
    fi
    add_change "${WEBMAIL_CAPABILITY}" would_ensure "${WEBMAIL_STATE_ROOT}" "state directory would be created, mode 1770 root:lesta-webmail (holds roundcube.db, written by lesta-webmail), with a root-only per-node des_key generated once and reused on every later apply"
    add_change "${WEBMAIL_CAPABILITY}" would_install "${DEPLOY_DIR}" "${ROUNDCUBE_ARTIFACT} would be checksum-verified and extracted here (only when the deployed version differs), owned root:lesta-webmail; public_html/ world-readable for nginx, config/ temp/ logs/ never readable by www-data"
    add_change "${WEBMAIL_CAPABILITY}" would_install "${PLUGIN_DEST_DIR}" "lesta_autologin.php checksum-verified and copied in; control-plane-url.txt parsed from ${DAEMON_CONFIG_PATH}"
    add_change "${WEBMAIL_CAPABILITY}" would_write "${ROUNDCUBE_CONFIG}" "config.inc.php for imap_host ssl://${MAIL_HOSTNAME}:993, smtp_host tls://${MAIL_HOSTNAME}:587, mode 0640 root:lesta-webmail"
    add_change "${WEBMAIL_CAPABILITY}" would_ensure "${ROUNDCUBE_DB}" "SQLite schema created or upgraded by Roundcube's own bin/initdb.sh --update, run as lesta-webmail"
    add_change "${WEBMAIL_CAPABILITY}" would_install "${WEBMAIL_POOL_CONF}" "a dedicated static PHP ${WEBMAIL_PHP_VERSION}-FPM pool (pm.max_children=4) would be written, listening on ${WEBMAIL_SOCKET_PATH}, open_basedir ${DEPLOY_DIR}:${WEBMAIL_STATE_ROOT}:/tmp; php${WEBMAIL_PHP_VERSION}-fpm would be reloaded"

    emit_result_and_exit would_change "${EXIT_OK}"
}

emit_apply_success_and_exit() {
    log_info "install.sh apply completed successfully"
    emit_result_and_exit applied "${EXIT_OK}"
}

# --- preflight orchestration --------------------------------------------

# preflight_check_dependency_package <package> <human_capability_label>
# The same real dpkg check adminer/install.sh uses: a dependency this
# installer never installs itself must genuinely be present already.
preflight_check_dependency_package() {
    local package="$1" label="$2"

    if ! dpkg-query -W -f='${Status}' "${package}" 2>/dev/null | grep -q '^install ok installed$'; then
        add_error dependency_not_installed "${label} is not installed on this node (dpkg-query found no installed ${package}); bootstrap it first (its own install.sh --apply)" "${package}"
        return 1
    fi

    return 0
}

# preflight_check_webmail_identity
# Fails when a lesta-webmail user already exists with a home or shell that
# does not match what bootstrap_webmail_identity would create. Mirrors
# adminer/install.sh's preflight_check_adminer_identity exactly.
preflight_check_webmail_identity() {
    local expected_home="/var/lib/lesta" expected_shell="/usr/sbin/nologin" home shell

    if getent passwd lesta-webmail >/dev/null 2>&1; then
        home=$(getent passwd lesta-webmail | awk -F: '{print $6}')
        shell=$(getent passwd lesta-webmail | awk -F: '{print $7}')

        if [ "${home}" != "${expected_home}" ] || [ "${shell}" != "${expected_shell}" ]; then
            add_error conflicting_identity "an existing lesta-webmail user has home=${home} shell=${shell}, which does not match what this installer would create (home=${expected_home} shell=${expected_shell})" "/etc/passwd"
            return 1
        fi
    fi

    return 0
}

# preflight_check_daemon_config_present
# Same as adminer/install.sh's: the plugin needs the control plane's own
# base URL, which only exists once this node has been enrolled.
preflight_check_daemon_config_present() {
    if [ ! -f "${DAEMON_CONFIG_PATH}" ]; then
        add_error daemon_config_missing "${DAEMON_CONFIG_PATH} does not exist; this node has not completed agent-daemon enrollment yet (run .install/services/agent-daemon/install.sh --apply first), so mail.webmail.v1 has no control plane URL to write into control-plane-url.txt" "${DAEMON_CONFIG_PATH}"
        return 1
    fi

    return 0
}

# preflight_check_mail_tls_certificate
# Mirrors mail/install.sh's own check exactly: Roundcube connects to Dovecot
# and Exim by this hostname with TLS verification on, and nginx serves
# webmail on this same hostname's certificate, so a missing certificate
# fails closed here rather than producing an install that can never log in.
preflight_check_mail_tls_certificate() {
    local cert_dir="${ACME_CERTS_ROOT}/${MAIL_HOSTNAME}"

    if [ ! -f "${cert_dir}/fullchain.pem" ] || [ ! -f "${cert_dir}/privkey.pem" ]; then
        add_error mail_tls_certificate_missing "no certificate found at ${cert_dir}/{fullchain,privkey}.pem for --mail-hostname ${MAIL_HOSTNAME}; create a WebDomain for this hostname first (any web profile) and let the existing ACME flow issue its certificate, then rerun this installer" "${cert_dir}"
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

    # Deliberately excludes /run (tmpfs, sized from RAM): see
    # adminer/install.sh's own identical comment.
    for dir in /etc /var/lib /var/www; do
        preflight_check_capacity "${dir}" || failed=1
    done

    # No port-free check: the manifest declares ports: [], the pool listens
    # on a unix socket only, dialed by nginx's own already-bootstrapped vhost.
    preflight_check_dependency_package "php${WEBMAIL_PHP_VERSION}-fpm" "web.php-fpm.v1 (PHP ${WEBMAIL_PHP_VERSION}-FPM)" || failed=1
    preflight_check_dependency_package "nginx" "web.nginx.v1" || failed=1
    preflight_check_dependency_package "dovecot-imapd" "mail.smtp-imap.v1 (Dovecot IMAP)" || failed=1
    preflight_check_dependency_package "exim4-daemon-heavy" "mail.smtp-imap.v1 (Exim)" || failed=1
    preflight_check_webmail_identity || failed=1
    preflight_check_daemon_config_present || failed=1
    preflight_check_mail_tls_certificate || failed=1

    if [ ! -f "${ROUNDCUBE_TARBALL_SRC}" ] || [ ! -f "${PLUGIN_SRC}" ]; then
        add_error artifact_missing "${VENDOR_DIR} is missing ${ROUNDCUBE_ARTIFACT} and/or lesta_autologin/${PLUGIN_ARTIFACT}" "${VENDOR_DIR}"
        failed=1
    fi

    if [ "${failed}" -ne 0 ]; then
        emit_result_and_exit failed "${EXIT_PREFLIGHT_CONFLICT}"
    fi

    log_info "preflight passed"
}

# --- phase 1: bootstrap_webmail_identity ---------------------------------

ensure_lesta_group() {
    getent group lesta >/dev/null 2>&1 || groupadd --system lesta
}

bootstrap_webmail_identity() {
    log_info "bootstrap_webmail_identity: ensuring lesta-webmail system user/group"

    if ! getent group lesta-webmail >/dev/null 2>&1; then
        groupadd --system lesta-webmail || fail_step "${EXIT_MUTATION_FAILURE}" groupadd_failed /etc/group "failed to create group lesta-webmail"
        add_change "${WEBMAIL_CAPABILITY}" created /etc/group "created dedicated system group lesta-webmail"
    else
        add_change "${WEBMAIL_CAPABILITY}" verified /etc/group "group lesta-webmail already exists"
    fi

    if ! getent passwd lesta-webmail >/dev/null 2>&1; then
        useradd --system --gid lesta-webmail --home-dir /var/lib/lesta --no-create-home \
            --shell /usr/sbin/nologin --comment "LESta webmail pool identity" lesta-webmail \
            || fail_step "${EXIT_MUTATION_FAILURE}" useradd_failed /etc/passwd "failed to create system user lesta-webmail"
        add_change "${WEBMAIL_CAPABILITY}" created /etc/passwd "created dedicated system user lesta-webmail, group lesta-webmail, no home, no login shell"
    else
        add_change "${WEBMAIL_CAPABILITY}" verified /etc/passwd "system user lesta-webmail already exists"
    fi

    checkpoint_write bootstrap_webmail_identity "${MANIFEST_DIGEST}"
    log_info "bootstrap_webmail_identity complete"
}

# --- phase 2: install_php_extensions -------------------------------------

# Only touches apt at all when something is actually missing, so a re-apply
# on an already-converged node is a pure no-op. Same invocation and error
# reporting shape as php-fpm/install.sh's own install_php_fpm.
install_php_extensions() {
    local missing out

    missing=$(missing_php_packages)

    if [ -z "${missing}" ]; then
        add_change "${WEBMAIL_CAPABILITY}" verified "" "every required PHP package is already installed (${WEBMAIL_PHP_PACKAGES})"
        checkpoint_write install_php_extensions "${MANIFEST_DIGEST}"
        return 0
    fi

    log_info "install_php_extensions: installing ${missing}"

    if ! out=$(DEBIAN_FRONTEND=noninteractive apt-get update 2>&1); then
        add_error apt_update_failed "$(printf '%s' "${out}" | tr '\n' ' ')" ""
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi

    # shellcheck disable=SC2086
    if ! out=$(DEBIAN_FRONTEND=noninteractive apt-get install -y ${missing} 2>&1); then
        add_error apt_install_failed "$(printf '%s' "${out}" | tr '\n' ' ')" ""
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi
    add_change "${WEBMAIL_CAPABILITY}" installed "" "apt-get install -y ${missing} succeeded"

    checkpoint_write install_php_extensions "${MANIFEST_DIGEST}"
    log_info "install_php_extensions complete"
}

# --- phase 3: bootstrap_state_root ---------------------------------------

# Unlike Adminer's pure presence marker, this directory also holds
# roundcube.db, which lesta-webmail must be able to create and write. SQLite
# needs write access to the directory itself, not just the file (it creates
# -wal/-shm files next to the database: Roundcube's own rcube_db_sqlite turns
# on journal_mode=WAL). Hence root:lesta-webmail 1770: group-writable for
# lesta-webmail, and sticky, so lesta-webmail can never unlink or replace the
# root-owned des_key/deployed-version files below. The agent's presence
# check (os.Stat) only needs /var/lib/lesta's own 0751 traverse bit.
#
# Created before the rest of the install (the database and key live here),
# so a failed apply can leave it present and the agent reporting
# mail.webmail.v1 as installed until a rerun converges. Laravel still never
# routes webmail anywhere without an admin-declared NodeCapability row.
bootstrap_state_root() {
    log_info "bootstrap_state_root: creating ${WEBMAIL_STATE_ROOT}"

    ensure_lesta_group

    install -d -m 1770 -o root -g lesta-webmail "${WEBMAIL_STATE_ROOT}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${WEBMAIL_STATE_ROOT}" "failed to create ${WEBMAIL_STATE_ROOT}"
    add_change "${WEBMAIL_CAPABILITY}" ensured "${WEBMAIL_STATE_ROOT}" "state directory present, mode 1770 root:lesta-webmail"

    ensure_des_key

    checkpoint_write bootstrap_state_root "${MANIFEST_DIGEST}"
    log_info "bootstrap_state_root complete"
}

# valid_des_key <value> -> exit 0 if value is exactly 24 characters from
# [A-Za-z0-9]. The length check alone also rules out embedded newlines, so
# the value is always safe to embed in config.inc.php's single-quoted string.
valid_des_key() {
    [ "${#1}" -eq 24 ] && printf '%s' "$1" | grep -Eqx '[A-Za-z0-9]{24}'
}

# ensure_des_key
# Roundcube's des_key encrypts the IMAP password it keeps in each session, so
# it is generated once per node and reused on every later apply (rotating it
# would only log everyone out). An existing key is only trusted when it is a
# root-owned regular file with a valid value; anything else fails closed
# rather than being silently replaced or embedded in PHP.
ensure_des_key() {
    local key

    if [ -e "${DES_KEY_FILE}" ]; then
        if [ ! -f "${DES_KEY_FILE}" ] || [ -L "${DES_KEY_FILE}" ] || [ "$(stat -c %u "${DES_KEY_FILE}")" != "0" ]; then
            fail_step "${EXIT_PREFLIGHT_CONFLICT}" des_key_untrusted "${DES_KEY_FILE}" "${DES_KEY_FILE} exists but is not a root-owned regular file; inspect it and remove it by hand to have a new key generated"
        fi

        key=$(cat "${DES_KEY_FILE}")

        if ! valid_des_key "${key}"; then
            fail_step "${EXIT_PREFLIGHT_CONFLICT}" des_key_invalid "${DES_KEY_FILE}" "${DES_KEY_FILE} does not hold a 24-character alphanumeric key; inspect it and remove it by hand to have a new key generated"
        fi

        add_change "${WEBMAIL_CAPABILITY}" verified "${DES_KEY_FILE}" "existing per-node des_key reused"
        return 0
    fi

    key=$(head -c 512 /dev/urandom | LC_ALL=C tr -dc 'A-Za-z0-9' | head -c 24)

    if ! valid_des_key "${key}"; then
        fail_step "${EXIT_MUTATION_FAILURE}" des_key_generation_failed "${DES_KEY_FILE}" "failed to generate a 24-character des_key from /dev/urandom"
    fi

    (umask 077 && printf '%s\n' "${key}" > "${DES_KEY_FILE}.tmp") \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${DES_KEY_FILE}" "failed to write ${DES_KEY_FILE}.tmp"
    chown root:root "${DES_KEY_FILE}.tmp" \
        || fail_step "${EXIT_MUTATION_FAILURE}" chown_failed "${DES_KEY_FILE}" "failed to chown des_key to root:root"
    chmod 0600 "${DES_KEY_FILE}.tmp" \
        || fail_step "${EXIT_MUTATION_FAILURE}" chmod_failed "${DES_KEY_FILE}" "failed to chmod des_key to 0600"
    mv -f "${DES_KEY_FILE}.tmp" "${DES_KEY_FILE}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${DES_KEY_FILE}" "failed to activate ${DES_KEY_FILE}"
    add_change "${WEBMAIL_CAPABILITY}" created "${DES_KEY_FILE}" "new per-node des_key generated, mode 0600 root:root"
}

# --- phase 4: deploy_roundcube -------------------------------------------

# extract_roundcube_if_needed
# Re-extracts only when deployed-version (Roundcube version + tarball sha256)
# differs from what this installer ships, so a re-apply never rewrites the
# tree. Extraction goes to a staging directory that is swapped in whole, so
# a failed extract never leaves a half-written live tree. temp/ and logs/ are
# not carried over across a version change: both are disposable caches.
extract_roundcube_if_needed() {
    local expected_sha256 wanted current=""

    expected_sha256=$(manifest_artifact_sha256 "${WEBMAIL_MANIFEST}" "${ROUNDCUBE_ARTIFACT}")
    if [ -z "${expected_sha256}" ]; then
        fail_step "${EXIT_VERIFICATION_FAILURE}" artifact_not_declared "${WEBMAIL_MANIFEST}" "webmail manifest has no ${ROUNDCUBE_ARTIFACT} artifacts[] entry"
    fi

    wanted="${ROUNDCUBE_VERSION} ${expected_sha256}"
    if [ -f "${DEPLOYED_VERSION_FILE}" ]; then
        current=$(cat "${DEPLOYED_VERSION_FILE}")
    fi

    if [ "${current}" = "${wanted}" ] && [ -f "${DEPLOY_DIR}/public_html/index.php" ]; then
        add_change "${WEBMAIL_CAPABILITY}" verified "${DEPLOY_DIR}" "Roundcube ${ROUNDCUBE_VERSION} already deployed (${expected_sha256}); not re-extracted"
        return 0
    fi

    verify_sha256 "${ROUNDCUBE_TARBALL_SRC}" "${expected_sha256}" \
        || fail_step "${EXIT_VERIFICATION_FAILURE}" checksum_mismatch "${ROUNDCUBE_TARBALL_SRC}" "${ROUNDCUBE_ARTIFACT} sha256 does not match webmail manifest's artifacts[] entry"

    rm -rf "${DEPLOY_STAGING_DIR}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" cleanup_failed "${DEPLOY_STAGING_DIR}" "failed to remove a stale ${DEPLOY_STAGING_DIR}"
    install -d -m 0750 -o root -g lesta-webmail "${DEPLOY_STAGING_DIR}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${DEPLOY_STAGING_DIR}" "failed to create ${DEPLOY_STAGING_DIR}"

    # Only the one expected top-level directory is extracted, with its
    # prefix stripped and the tarball's own recorded owners ignored.
    tar -xzf "${ROUNDCUBE_TARBALL_SRC}" -C "${DEPLOY_STAGING_DIR}" --no-same-owner --strip-components=1 "${ROUNDCUBE_TOP_DIR}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" extract_failed "${DEPLOY_STAGING_DIR}" "failed to extract ${ROUNDCUBE_ARTIFACT}"

    if [ ! -f "${DEPLOY_STAGING_DIR}/public_html/index.php" ] || [ ! -f "${DEPLOY_STAGING_DIR}/bin/initdb.sh" ]; then
        fail_step "${EXIT_VERIFICATION_FAILURE}" unexpected_layout "${DEPLOY_STAGING_DIR}" "${ROUNDCUBE_ARTIFACT} did not extract to the expected Roundcube layout (public_html/index.php, bin/initdb.sh)"
    fi

    # Roundcube's own guidance for production: the web installer must not
    # stay reachable. enable_installer = false already disables it; removing
    # the files as well means a config mistake can never re-expose it.
    rm -rf "${DEPLOY_STAGING_DIR}/installer" "${DEPLOY_STAGING_DIR}/public_html/installer.php" \
        || fail_step "${EXIT_MUTATION_FAILURE}" cleanup_failed "${DEPLOY_STAGING_DIR}/installer" "failed to remove Roundcube's web installer"

    rm -rf "${DEPLOY_PREVIOUS_DIR}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" cleanup_failed "${DEPLOY_PREVIOUS_DIR}" "failed to remove a stale ${DEPLOY_PREVIOUS_DIR}"
    if [ -d "${DEPLOY_DIR}" ]; then
        mv "${DEPLOY_DIR}" "${DEPLOY_PREVIOUS_DIR}" \
            || fail_step "${EXIT_MUTATION_FAILURE}" swap_failed "${DEPLOY_DIR}" "failed to move the previous deployment aside"
    fi
    mv "${DEPLOY_STAGING_DIR}" "${DEPLOY_DIR}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" swap_failed "${DEPLOY_DIR}" "failed to activate the new deployment"
    rm -rf "${DEPLOY_PREVIOUS_DIR}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" cleanup_failed "${DEPLOY_PREVIOUS_DIR}" "failed to remove the previous deployment"

    printf '%s\n' "${wanted}" > "${DEPLOYED_VERSION_FILE}.tmp" \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${DEPLOYED_VERSION_FILE}" "failed to write ${DEPLOYED_VERSION_FILE}.tmp"
    chmod 0644 "${DEPLOYED_VERSION_FILE}.tmp" \
        || fail_step "${EXIT_MUTATION_FAILURE}" chmod_failed "${DEPLOYED_VERSION_FILE}" "failed to chmod deployed-version"
    mv -f "${DEPLOYED_VERSION_FILE}.tmp" "${DEPLOYED_VERSION_FILE}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${DEPLOYED_VERSION_FILE}" "failed to activate ${DEPLOYED_VERSION_FILE}"

    add_change "${WEBMAIL_CAPABILITY}" installed "${DEPLOY_DIR}" "Roundcube ${ROUNDCUBE_VERSION} extracted from the repo-vendored tarball, sha256 verified against the manifest (${expected_sha256}); web installer removed"
}

deploy_plugin() {
    local expected_sha256 actual_sha256

    expected_sha256=$(manifest_artifact_sha256 "${WEBMAIL_MANIFEST}" "${PLUGIN_ARTIFACT}")
    if [ -z "${expected_sha256}" ]; then
        fail_step "${EXIT_VERIFICATION_FAILURE}" artifact_not_declared "${WEBMAIL_MANIFEST}" "webmail manifest has no ${PLUGIN_ARTIFACT} artifacts[] entry"
    fi

    verify_sha256 "${PLUGIN_SRC}" "${expected_sha256}" \
        || fail_step "${EXIT_VERIFICATION_FAILURE}" checksum_mismatch "${PLUGIN_SRC}" "${PLUGIN_ARTIFACT} sha256 does not match webmail manifest's artifacts[] entry"

    install -d -m 0750 -o root -g lesta-webmail "${PLUGIN_DEST_DIR}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${PLUGIN_DEST_DIR}" "failed to create ${PLUGIN_DEST_DIR}"

    if [ -f "${PLUGIN_DEST}" ]; then
        actual_sha256=$(compute_sha256 "${PLUGIN_DEST}")
        if [ "${actual_sha256}" = "${expected_sha256}" ]; then
            add_change "${WEBMAIL_CAPABILITY}" verified "${PLUGIN_DEST}" "${PLUGIN_ARTIFACT} already at the target sha256 (${expected_sha256}); no-op"
            return 0
        fi
    fi

    cp "${PLUGIN_SRC}" "${PLUGIN_DEST}.tmp" || fail_step "${EXIT_MUTATION_FAILURE}" copy_failed "${PLUGIN_DEST}" "failed to copy ${PLUGIN_ARTIFACT} into place"
    mv -f "${PLUGIN_DEST}.tmp" "${PLUGIN_DEST}" || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${PLUGIN_DEST}" "failed to activate ${PLUGIN_DEST}"
    add_change "${WEBMAIL_CAPABILITY}" installed "${PLUGIN_DEST}" "${PLUGIN_ARTIFACT} copied from the repo-vendored source, sha256 verified against the manifest (${expected_sha256})"
}

# parse_control_plane_url <daemon_config_path> -> the control_plane_url
# field's value, or nothing. Identical to adminer/install.sh's own.
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

    printf '%s\n' "${control_plane_url}" > "${CONTROL_PLANE_URL_FILE}.tmp" \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${CONTROL_PLANE_URL_FILE}" "failed to write ${CONTROL_PLANE_URL_FILE}.tmp"
    mv -f "${CONTROL_PLANE_URL_FILE}.tmp" "${CONTROL_PLANE_URL_FILE}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${CONTROL_PLANE_URL_FILE}" "failed to activate ${CONTROL_PLANE_URL_FILE}"
    add_change "${WEBMAIL_CAPABILITY}" installed "${CONTROL_PLANE_URL_FILE}" "control plane base URL (${control_plane_url}) parsed from ${DAEMON_CONFIG_PATH} and written here"
}

# write_roundcube_config
# Every option name below was checked against the real
# config/defaults.inc.php inside roundcubemail-1.7.4-complete.tar.gz.
# MAIL_HOSTNAME is regex-validated (validate_mail_hostname) and the des_key
# is [A-Za-z0-9]{24} (valid_des_key), so neither can break out of the
# single-quoted PHP strings they are embedded in.
write_roundcube_config() {
    local des_key saved_umask

    des_key=$(cat "${DES_KEY_FILE}")

    # umask 027: the file carries des_key, so it is never world-readable,
    # not even for the moment before its own chmod below.
    saved_umask=$(umask)
    umask 027
    cat > "${ROUNDCUBE_CONFIG}.tmp" <<CONFIG
<?php

// Managed by LESta's own mail.webmail.v1 capability
// (.install/services/webmail/install.sh). Do not edit by hand: every apply
// overwrites it unconditionally.

\$config = [];

// SQLite under the capability's own state root (see install.sh's
// bootstrap_state_root for why lesta-webmail can write there).
\$config['db_dsnw'] = 'sqlite:///${ROUNDCUBE_DB}?mode=0640';

// Always this node's own Dovecot/Exim, by the mail hostname their own
// certificate names. Never a host picker: a login (native form or
// lesta_autologin) can only ever reach this node's own mail stack.
\$config['imap_host'] = 'ssl://${MAIL_HOSTNAME}:993';
\$config['smtp_host'] = 'tls://${MAIL_HOSTNAME}:587';
\$config['smtp_user'] = '%u';
\$config['smtp_pass'] = '%p';

\$config['support_url'] = '';
\$config['product_name'] = 'Webmail';
\$config['des_key'] = '${des_key}';
\$config['plugins'] = ['lesta_autologin'];
\$config['skin'] = 'elastic';
\$config['enable_installer'] = false;
\$config['login_autocomplete'] = 2;
\$config['log_driver'] = 'file';
CONFIG
    umask "${saved_umask}"
    chown root:lesta-webmail "${ROUNDCUBE_CONFIG}.tmp" \
        || fail_step "${EXIT_MUTATION_FAILURE}" chown_failed "${ROUNDCUBE_CONFIG}" "failed to chown config.inc.php to root:lesta-webmail"
    chmod 0640 "${ROUNDCUBE_CONFIG}.tmp" \
        || fail_step "${EXIT_MUTATION_FAILURE}" chmod_failed "${ROUNDCUBE_CONFIG}" "failed to chmod config.inc.php to 0640"
    mv -f "${ROUNDCUBE_CONFIG}.tmp" "${ROUNDCUBE_CONFIG}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${ROUNDCUBE_CONFIG}" "failed to activate ${ROUNDCUBE_CONFIG}"
    add_change "${WEBMAIL_CAPABILITY}" installed "${ROUNDCUBE_CONFIG}" "config.inc.php written: imap_host ssl://${MAIL_HOSTNAME}:993, smtp_host tls://${MAIL_HOSTNAME}:587, db ${ROUNDCUBE_DB}, plugins lesta_autologin"
}

# apply_roundcube_permissions
# Who needs what:
#   - lesta-webmail (the pool): read everything, write only temp/ and logs/.
#     Code and config are root-owned, so a compromised pool can never
#     rewrite Roundcube itself or its own config.
#   - www-data (nginx): only traverse into public_html/ and stat its
#     index.php/static.php (webmail.conf.tmpl's try_files). Roundcube 1.7
#     serves every skin/plugin/js asset through public_html/static.php, run
#     by the pool, so nginx never reads skins/, plugins/ or program/ itself.
#     public_html/ holds only upstream's public entry points, so it is
#     world-readable; DEPLOY_DIR itself is 0751 (traverse, no listing).
#   - Nobody else: config/ (des_key), temp/, logs/ and everything outside
#     public_html/ stay 0750/0640, never readable by www-data or others.
apply_roundcube_permissions() {
    local dir

    chown -R root:lesta-webmail "${DEPLOY_DIR}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" chown_failed "${DEPLOY_DIR}" "failed to chown ${DEPLOY_DIR} to root:lesta-webmail"
    find "${DEPLOY_DIR}" -type d -exec chmod 0750 {} + \
        || fail_step "${EXIT_MUTATION_FAILURE}" chmod_failed "${DEPLOY_DIR}" "failed to chmod directories under ${DEPLOY_DIR}"
    find "${DEPLOY_DIR}" -type f -exec chmod 0640 {} + \
        || fail_step "${EXIT_MUTATION_FAILURE}" chmod_failed "${DEPLOY_DIR}" "failed to chmod files under ${DEPLOY_DIR}"

    chmod 0751 "${DEPLOY_DIR}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" chmod_failed "${DEPLOY_DIR}" "failed to chmod ${DEPLOY_DIR} to 0751"
    chmod 0755 "${DEPLOY_DIR}/public_html" \
        || fail_step "${EXIT_MUTATION_FAILURE}" chmod_failed "${DEPLOY_DIR}/public_html" "failed to chmod public_html to 0755"
    find "${DEPLOY_DIR}/public_html" -type f -exec chmod 0644 {} + \
        || fail_step "${EXIT_MUTATION_FAILURE}" chmod_failed "${DEPLOY_DIR}/public_html" "failed to chmod files under public_html"

    for dir in "${DEPLOY_DIR}/temp" "${DEPLOY_DIR}/logs"; do
        install -d -m 0750 -o lesta-webmail -g lesta-webmail "${dir}" \
            || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${dir}" "failed to create ${dir}"
        chown -R lesta-webmail:lesta-webmail "${dir}" \
            || fail_step "${EXIT_MUTATION_FAILURE}" chown_failed "${dir}" "failed to chown ${dir} to lesta-webmail"
    done

    add_change "${WEBMAIL_CAPABILITY}" ensured "${DEPLOY_DIR}" "ownership root:lesta-webmail (dirs 0750, files 0640); ${DEPLOY_DIR} 0751 and public_html/ world-readable for nginx; temp/ and logs/ owned lesta-webmail 0750"
}

deploy_roundcube() {
    log_info "deploy_roundcube: deploying Roundcube ${ROUNDCUBE_VERSION} to ${DEPLOY_DIR}"

    extract_roundcube_if_needed
    deploy_plugin
    write_control_plane_url_file
    write_roundcube_config
    apply_roundcube_permissions

    checkpoint_write deploy_roundcube "${MANIFEST_DIGEST}"
    log_info "deploy_roundcube complete"
}

# --- phase 5: init_roundcube_db ------------------------------------------

# Roundcube's own bin/initdb.sh, run as lesta-webmail so roundcube.db (and
# its -wal/-shm files) are owned by the pool's own identity from the start.
# --update makes it idempotent: on an existing database (system table
# present) it applies only pending schema updates, otherwise it creates the
# schema from SQL/sqlite.initial.sql.
init_roundcube_db() {
    local out

    log_info "init_roundcube_db: creating or upgrading ${ROUNDCUBE_DB}"

    if ! out=$(cd "${DEPLOY_DIR}" && sudo -u lesta-webmail "/usr/bin/php${WEBMAIL_PHP_VERSION}" "${DEPLOY_DIR}/bin/initdb.sh" --dir="${DEPLOY_DIR}/SQL" --update 2>&1); then
        add_error initdb_failed "$(printf '%s' "${out}" | tr '\n' ' ')" "${ROUNDCUBE_DB}"
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi

    if [ ! -f "${ROUNDCUBE_DB}" ]; then
        fail_step "${EXIT_HEALTH_FAILURE}" initdb_no_database "${ROUNDCUBE_DB}" "bin/initdb.sh exited 0 but ${ROUNDCUBE_DB} does not exist"
    fi

    add_change "${WEBMAIL_CAPABILITY}" ensured "${ROUNDCUBE_DB}" "SQLite schema present and current (bin/initdb.sh --update, as lesta-webmail): $(printf '%s' "${out}" | tr '\n' ' ')"

    checkpoint_write init_roundcube_db "${MANIFEST_DIGEST}"
    log_info "init_roundcube_db complete"
}

# --- phase 6: install_webmail_pool ---------------------------------------

# Disable_functions list copied verbatim from adminer/install.sh's own pool
# (itself copied from the tenant pool template): defense in depth, never the
# primary boundary, which is this pool's own dedicated lesta-webmail uid/gid.
install_webmail_pool() {
    log_info "install_webmail_pool: writing dedicated PHP ${WEBMAIL_PHP_VERSION}-FPM pool"

    install -d -m 0750 -o lesta-webmail -g www-data "${WEBMAIL_SOCKET_DIR}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${WEBMAIL_SOCKET_DIR}" "failed to create ${WEBMAIL_SOCKET_DIR}"
    add_change "${WEBMAIL_CAPABILITY}" ensured "${WEBMAIL_SOCKET_DIR}" "socket directory present, mode 0750 lesta-webmail:www-data (nginx, running as www-data, must be able to traverse in and dial the socket)"

    # /run is tmpfs: a systemd-tmpfiles rule makes the directory survive a
    # reboot (mirrors adminer/install.sh's own /run/lesta-adminer rule).
    cat > /etc/tmpfiles.d/lesta-webmail.conf.tmp <<TMPFILES
d ${WEBMAIL_SOCKET_DIR} 0750 lesta-webmail www-data -
TMPFILES
    mv -f /etc/tmpfiles.d/lesta-webmail.conf.tmp /etc/tmpfiles.d/lesta-webmail.conf \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed /etc/tmpfiles.d/lesta-webmail.conf "failed to activate /etc/tmpfiles.d/lesta-webmail.conf"
    systemd-tmpfiles --create /etc/tmpfiles.d/lesta-webmail.conf >/dev/null 2>&1 || true
    add_change "${WEBMAIL_CAPABILITY}" installed /etc/tmpfiles.d/lesta-webmail.conf "systemd-tmpfiles rule written so ${WEBMAIL_SOCKET_DIR} survives a reboot (/run is tmpfs)"

    cat > "${WEBMAIL_POOL_CONF}.tmp" <<POOLCONF
; Managed by LESta's own mail.webmail.v1 capability. Do not edit by hand --
; a future apply of .install/services/webmail/install.sh will overwrite it
; unconditionally.
[lesta-webmail]
user = lesta-webmail
group = lesta-webmail
listen = ${WEBMAIL_SOCKET_PATH}
listen.owner = lesta-webmail
listen.group = www-data
listen.mode = 0660

; Static, not dynamic: one fixed, node-wide webmail instance, never a
; per-tenant pool sized for real traffic.
pm = static
pm.max_children = 4

; Defense in depth, never the primary boundary (that is this pool's own
; dedicated lesta-webmail OS uid/gid). open_basedir covers only Roundcube's
; own deploy directory, its own state root (roundcube.db) and /tmp
; (uploads), never a tenant docroot or any mailbox's own files: Roundcube
; reaches mail only over IMAP/SMTP, as the logged-in mailbox.
php_admin_value[open_basedir] = ${DEPLOY_DIR}:${WEBMAIL_STATE_ROOT}:/tmp
php_admin_value[disable_functions] = exec,shell_exec,system,popen,proc_open,pcntl_exec,pcntl_fork,pcntl_signal

; Deliberately ON, unlike a tenant's own pool: lesta_autologin.php's own
; credential handoff (lesta_autologin::redeem()) makes exactly one outbound
; HTTPS call to this node's own known, fixed control plane URL via
; file_get_contents() -- the only outbound URL fetch this pool ever needs,
; and a narrow, justified exception to the tenant-pool default precisely
; because that default (preventing arbitrary tenant PHP from reaching
; arbitrary internal/external URLs, a real SSRF boundary) does not apply
; here: this pool never runs tenant-authored code at all. Found the hard way
; on a real node with Adminer's identical handoff: file_get_contents() over
; https:// silently returns false with allow_url_fopen off.
php_admin_flag[allow_url_fopen] = on

; Matches webmail.conf.tmpl's own client_max_body_size 25m, so an
; attachment nginx accepts is never then rejected by PHP.
php_admin_value[upload_max_filesize] = 25M
php_admin_value[post_max_size] = 25M
POOLCONF
    mv -f "${WEBMAIL_POOL_CONF}.tmp" "${WEBMAIL_POOL_CONF}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${WEBMAIL_POOL_CONF}" "failed to activate ${WEBMAIL_POOL_CONF}"
    add_change "${WEBMAIL_CAPABILITY}" installed "${WEBMAIL_POOL_CONF}" "dedicated static pool written: pm.max_children=4, listen=${WEBMAIL_SOCKET_PATH}, open_basedir=${DEPLOY_DIR}:${WEBMAIL_STATE_ROOT}:/tmp"

    if ! "/usr/sbin/php-fpm${WEBMAIL_PHP_VERSION}" -t >/dev/null 2>&1; then
        fail_step "${EXIT_HEALTH_FAILURE}" pool_config_invalid "${WEBMAIL_POOL_CONF}" "php-fpm${WEBMAIL_PHP_VERSION} -t rejected the rendered pool config"
    fi

    systemctl reload "php${WEBMAIL_PHP_VERSION}-fpm" \
        || fail_step "${EXIT_HEALTH_FAILURE}" systemctl_reload_failed "" "systemctl reload php${WEBMAIL_PHP_VERSION}-fpm failed"
    add_change "${WEBMAIL_CAPABILITY}" reloaded "" "systemctl reload php${WEBMAIL_PHP_VERSION}-fpm succeeded"

    checkpoint_write install_webmail_pool "${MANIFEST_DIGEST}"
    log_info "install_webmail_pool complete"
}

# --- phase 7: run_webmail_selftest ---------------------------------------

# Same health proof as adminer/install.sh's own self-test: php-fpm -t passed
# above, and the real unix socket appears after the reload.
run_webmail_selftest() {
    local waited=0

    while [ ! -S "${WEBMAIL_SOCKET_PATH}" ] && [ "${waited}" -lt 10 ]; do
        sleep 1
        waited=$((waited + 1))
    done

    if [ ! -S "${WEBMAIL_SOCKET_PATH}" ]; then
        fail_step "${EXIT_HEALTH_FAILURE}" selftest_socket_missing "${WEBMAIL_SOCKET_PATH}" "php${WEBMAIL_PHP_VERSION}-fpm was reloaded but no real unix socket appeared at ${WEBMAIL_SOCKET_PATH} within 10s"
    fi

    add_change "${WEBMAIL_CAPABILITY}" verified "${WEBMAIL_SOCKET_PATH}" "php-fpm${WEBMAIL_PHP_VERSION} -t passed and the real unix socket exists and is live after reload"
    log_info "run_webmail_selftest: ${WEBMAIL_SOCKET_PATH} is live"
}

# --- main -------------------------------------------------------------

main() {
    MANIFEST_DIGEST=$(compute_manifest_digest "${BASE_MANIFEST}" "${NODE_HEALTH_MANIFEST}" "${WEBMAIL_MANIFEST}")

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

    bootstrap_webmail_identity
    install_php_extensions
    bootstrap_state_root
    deploy_roundcube
    init_roundcube_db
    install_webmail_pool
    run_webmail_selftest

    checkpoint_remove
    release_write "${RELEASE_ID}" "${MANIFEST_DIGEST}"

    emit_apply_success_and_exit
}

main "$@"
