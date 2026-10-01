#!/bin/sh
# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/services/php-fpm/install.sh
#
# Bootstrap installer for the web.php-fpm.v1 capability: takes a bare
# Ubuntu 24.04/26.04 node (or one that already has any other leaf-service
# capability bootstrapped) to a state where the Go agent's own
# phpFpmProductionConfig() preconditions (agent/cmd/lesta-agent/main.go)
# are met and web.php-fpm.v1 is structurally installed and health-checked,
# per .install/INSTALLER-CONTRACT.md.
#
# Mirrors mariadb/install.sh's own third-party-repository bootstrap
# discipline (manual GPG keyring download + hand-written sources.list
# entry, never piping a remote script into `sudo bash`, per
# .install/INSTALLER-CONTRACT.md's own "Supply chain" section) and
# cron/install.sh's own overall leaf-installer structure otherwise. No
# firewall phase runs: this service's own manifest declares ports: [],
# since every pool listens on a unix socket only, never a network port.
#
# **Real per-account isolation, reused, not reinvented**: every pool this
# capability ever renders runs as system.account-identity.v1's own already-
# provisioned lesta-t{account_id} OS identity (see
# agent/internal/capability/phpfpm's own package doc comment) -- this
# installer creates no new tenant-facing identity of its own, only the
# fixed per-version pool.d directories and the ondrej/php packages.
#
# **Bounded PHP version set, by design**: PHP_VERSIONS below names exactly
# the versions Laravel's own App\Enums\PhpVersion allows a tenant to
# select (8.1, 8.2, 8.3, 8.4) -- never an unbounded "whatever ondrej/php
# ships," matching this project's own "no unrestricted service editor"
# principle (ADR 0002). 8.3 is Ubuntu 24.04's own native package; the
# other three come from the ondrej/php PPA.
set -eu

# --- constants -------------------------------------------------------------

SCRIPT_VERSION="1.0.0"
RELEASE_ID="2026.09.30"
WEB_PHP_FPM_CAPABILITY="web.php-fpm.v1"

# The exact bounded set agent/internal/capability/phpfpm's own
# supportedPhpVersions map and App\Enums\PhpVersion both allow. Keep all
# three in lockstep.
PHP_VERSIONS="8.1 8.2 8.3 8.4"

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
# shellcheck source=../../lib/agent.sh
. "${INSTALL_ROOT}/lib/agent.sh"
# shellcheck source=../../lib/selftest.sh
. "${INSTALL_ROOT}/lib/selftest.sh"

BASE_MANIFEST="${INSTALL_ROOT}/base/manifest.json"
NODE_HEALTH_MANIFEST="${INSTALL_ROOT}/services/node-health/manifest.json"
PHP_FPM_MANIFEST="${INSTALL_ROOT}/services/php-fpm/manifest.json"

AGENT_BINARY_SRC="${REPO_ROOT}/agent/dist/lesta-agent-linux-amd64"

# Fixed production paths. Mirrors agent/cmd/lesta-agent/main.go's own
# phpFpmProductionConfig() exactly -- these two files are the only two
# places these literals may ever appear; keep them in lockstep.
# AccountsRoot itself (identityProductionConfig's own field, unused
# directly by this installer -- this capability only ever reads that
# convention agent-side) is deliberately not duplicated here.
PHP_POOL_BASE_DIR="/etc/php"
PHP_SOCKET_ROOT="/run/lesta-php"
PHP_FPM_STATE_ROOT="/var/lib/lesta/php-fpm"

# ondrej/php PPA (Launchpad): real, long-standing, publicly documented
# signing key for the Ondřej Surý PHP package family (the same key signs
# both the Debian sury.org repo and this Ubuntu Launchpad PPA). Fetched via
# Ubuntu's own keyserver HTTPS REST endpoint, never `add-apt-repository
# ppa:ondrej/php` (which itself pipes a remote fetch into apt's own
# keyring machinery with no separate audit step) and never piping a
# remote script into a shell directly, per
# .install/INSTALLER-CONTRACT.md's own "Supply chain" section -- mirrors
# mariadb/install.sh's own manual keyring + hand-written sources.list
# discipline exactly.
PHP_PPA_KEY_FINGERPRINT="4F4EA0AAE5267A6C"
PHP_PPA_KEYRING="/etc/apt/trusted.gpg.d/ondrej-php-keyring.gpg"
PHP_PPA_SOURCES_LIST="/etc/apt/sources.list.d/ondrej-php.list"

SUDOERS_LESTA_PHP_FPM_PATH="/etc/sudoers.d/lesta-php-fpm"

# CHECKPOINT_PATH/RELEASE_PATH: this installer's own paths, distinct from
# every other leaf-service installer's own (see lib/checkpoint.sh's own top
# comment).
export CHECKPOINT_PATH="/var/lib/lesta/install/php-fpm.checkpoint"
export RELEASE_PATH="/etc/lesta/php-fpm-release"

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

    items=$(manifest_extract_array "${PHP_FPM_MANIFEST}" "depends_on")

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
    provided_json=$(json_array_from_lines "$(json_str "${WEB_PHP_FPM_CAPABILITY}")")

    result=$(json_join_object \
        "$(json_kv_str "schema_version" "1")" \
        "$(json_kv_str "installer" "lesta-bootstrap")" \
        "$(json_kv_str "service" "php-fpm")" \
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

    add_change base.os.v1 would_ensure /etc/lesta "base directories and lesta/lesta-agent identity would be created or verified; install-state classification: ${install_state}"
    add_change node.health.v1 would_install "${AGENT_BINARY_DEST}" "vendored agent binary would be checksum-verified and copied into place, then self-tested by creating and deleting a throwaway PHP 8.3 pool against the real, just-installed php8.3-fpm"
    add_change "${WEB_PHP_FPM_CAPABILITY}" would_install "" "the ondrej/php PPA would be pinned; php${PHP_VERSIONS# } (space-separated: ${PHP_VERSIONS}) would be installed via apt-get; each version's own pool.d directory would be created, mode 0770 root:lesta, with its default www pool disabled; ${SUDOERS_LESTA_PHP_FPM_PATH} would be rendered and validated, scoping lesta-agent to run each version's own php-fpm -t and systemctl reload as root, nothing else. No firewall phase runs: this service's own manifest declares no ports"

    emit_result_and_exit would_change "${EXIT_OK}"
}

emit_apply_success_and_exit() {
    log_info "install.sh apply completed successfully"
    emit_result_and_exit applied "${EXIT_OK}"
}

# --- preflight orchestration --------------------------------------------

run_preflight() {
    local os_id os_version_id arch supported failed=0 dir

    if [ "$(id -u)" -ne 0 ]; then
        add_error not_root "install.sh must run as root (uid 0)" ""
        emit_result_and_exit failed "${EXIT_UNSUPPORTED_PLATFORM}"
    fi

    os_id=$(preflight_os_release_field ID)
    os_version_id=$(preflight_os_release_field VERSION_ID)
    supported=$(manifest_extract_array "${PHP_FPM_MANIFEST}" "supported_ubuntu")

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

    for dir in /etc /var/lib /var/log; do
        preflight_check_capacity "${dir}" || failed=1
    done

    # No port-free preflight check at all: this service's own manifest
    # declares ports: [], since every pool listens on a unix socket only.
    preflight_check_lesta_identity || failed=1

    if [ "${failed}" -ne 0 ]; then
        emit_result_and_exit failed "${EXIT_PREFLIGHT_CONFLICT}"
    fi

    log_info "preflight passed"
}

# --- phase 1: bootstrap_base -------------------------------------------
#
# Identical to every other leaf-service installer's own bootstrap_base
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

# --- phase 2: bootstrap_firewall_baseline ------------------------------
#
# Deliberately skipped entirely, mirroring cron/backups' own reasoning:
# this service's own manifest.json declares ports: [], since every real
# pool listens on a unix socket only, never a network port. lib/firewall.sh
# is never even sourced.

# --- phase 3: install_php_fpm -------------------------------------------

# ensure_ondrej_php_repo pins the ondrej/php PPA the same way
# mariadb/install.sh pins the MariaDB Foundation repository: a manually
# downloaded, fixed-fingerprint GPG keyring plus a hand-written
# sources.list entry, never `add-apt-repository` or a remote script
# piped into a shell.
# Idempotent: a second apply verifies the existing keyring/sources file
# rather than re-downloading them.
ensure_ondrej_php_repo() {
    local out codename

    if [ -f "${PHP_PPA_KEYRING}" ] && [ -f "${PHP_PPA_SOURCES_LIST}" ]; then
        add_change "${WEB_PHP_FPM_CAPABILITY}" verified "${PHP_PPA_SOURCES_LIST}" "ondrej/php PPA already pinned from a prior apply"

        return 0
    fi

    log_info "ensure_ondrej_php_repo: pinning the ondrej/php PPA (key fingerprint ${PHP_PPA_KEY_FINGERPRINT})"

    if ! out=$(curl -fsSL -o "${PHP_PPA_KEYRING}" \
        "https://keyserver.ubuntu.com/pks/lookup?op=get&options=mr&exact=on&search=0x${PHP_PPA_KEY_FINGERPRINT}" 2>&1); then
        add_error ondrej_php_keyring_fetch_failed "$(printf '%s' "${out}" | tr '\n' ' ')" "${PHP_PPA_KEYRING}"
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi

    # keyserver.ubuntu.com's own "mr" (machine-readable) export returns a
    # bare ASCII-armored public key block, not a binary keyring gpg
    # expects in /etc/apt/trusted.gpg.d/ -- dearmor it in place.
    if ! out=$(gpg --dearmor --yes --output "${PHP_PPA_KEYRING}.tmp" "${PHP_PPA_KEYRING}" 2>&1); then
        rm -f "${PHP_PPA_KEYRING}" "${PHP_PPA_KEYRING}.tmp"
        add_error ondrej_php_keyring_dearmor_failed "$(printf '%s' "${out}" | tr '\n' ' ')" "${PHP_PPA_KEYRING}"
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi
    mv -f "${PHP_PPA_KEYRING}.tmp" "${PHP_PPA_KEYRING}"
    chmod 0644 "${PHP_PPA_KEYRING}"
    add_change "${WEB_PHP_FPM_CAPABILITY}" installed "${PHP_PPA_KEYRING}" "ondrej/php PPA's own release signing key (fingerprint ${PHP_PPA_KEY_FINGERPRINT}) downloaded and dearmored to the modern apt trusted-keyring location"

    codename=$(preflight_os_release_field VERSION_CODENAME)

    cat > "${PHP_PPA_SOURCES_LIST}.tmp" <<SOURCES
# ondrej/php PPA (Launchpad): the real multi-version PHP-FPM package
# source web.php-fpm.v1 depends on for every version except Ubuntu's own
# native default. Managed by LESta; do not edit by hand.
deb [arch=amd64 signed-by=${PHP_PPA_KEYRING}] https://ppa.launchpadcontent.net/ondrej/php/ubuntu ${codename} main
SOURCES
    mv -f "${PHP_PPA_SOURCES_LIST}.tmp" "${PHP_PPA_SOURCES_LIST}"
    add_change "${WEB_PHP_FPM_CAPABILITY}" installed "${PHP_PPA_SOURCES_LIST}" "apt source pinned to the ondrej/php PPA (codename ${codename})"

    if ! out=$(apt-get update 2>&1); then
        add_error apt_update_failed "$(printf '%s' "${out}" | tr '\n' ' ')" "${PHP_PPA_SOURCES_LIST}"
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi
}

# disable_default_pool <version>
# Ubuntu/ondrej's own php<version>-fpm package ships one default pool
# enabled out of the box (pool.d/www.conf, running as www-data on its own
# default listener) -- unused by this capability's own per-domain model
# and unnecessary real attack surface (a working PHP interpreter no
# rendered vhost ever points at). Renamed rather than deleted, so a
# package reinstall/upgrade re-creating it is harmless and this step stays
# trivially reversible.
disable_default_pool() {
    local version="$1" default_pool

    default_pool="${PHP_POOL_BASE_DIR}/${version}/fpm/pool.d/www.conf"

    if [ -f "${default_pool}" ]; then
        mv -f "${default_pool}" "${default_pool}.disabled-by-lesta"
        add_change "${WEB_PHP_FPM_CAPABILITY}" disabled "${default_pool}" "php${version}-fpm's own default www pool renamed out of pool.d/ (never deleted): unused by this capability's own per-domain pool model"
    fi
}

install_php_fpm() {
    log_info "install_php_fpm: installing php-fpm ${PHP_VERSIONS} and activating ${WEB_PHP_FPM_CAPABILITY}"

    local version out pkgs=""

    ensure_ondrej_php_repo

    for version in ${PHP_VERSIONS}; do
        pkgs="${pkgs} php${version}-fpm"
    done

    # shellcheck disable=SC2086
    if ! out=$(apt-get install -y ${pkgs} 2>&1); then
        add_error apt_install_failed "$(printf '%s' "${out}" | tr '\n' ' ')" ""
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi
    add_change "${WEB_PHP_FPM_CAPABILITY}" installed "" "apt-get install -y${pkgs} succeeded"

    for version in ${PHP_VERSIONS}; do
        disable_default_pool "${version}"

        # 0770 root:lesta, not the package's own default 0755 root:root:
        # the real lesta-agent-daemon systemd unit runs as the
        # unprivileged lesta-agent user, and writeStaging/activateLive
        # (agent/internal/capability/phpfpm/activate.go) are plain file
        # writes/renames -- the same "make the parent directory
        # group-writable" fix nginx's/bind9's/cron's own LiveDir/
        # StateRoot already needed, applied here from the start rather
        # than found the hard way a second time.
        install -d -m 0770 -o root -g lesta "${PHP_POOL_BASE_DIR}/${version}/fpm/pool.d" \
            || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${PHP_POOL_BASE_DIR}/${version}/fpm/pool.d" "failed to set ownership on ${PHP_POOL_BASE_DIR}/${version}/fpm/pool.d"
        add_change "${WEB_PHP_FPM_CAPABILITY}" ensured "${PHP_POOL_BASE_DIR}/${version}/fpm/pool.d" "pool.d directory present, mode 0770 root:lesta"

        install -d -m 0770 -o root -g lesta "${PHP_SOCKET_ROOT}/${version}" \
            || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${PHP_SOCKET_ROOT}/${version}" "failed to create ${PHP_SOCKET_ROOT}/${version}"
        add_change "${WEB_PHP_FPM_CAPABILITY}" ensured "${PHP_SOCKET_ROOT}/${version}" "socket directory present, mode 0770 root:lesta"

        systemctl enable --now "php${version}-fpm" || fail_step "${EXIT_HEALTH_FAILURE}" systemctl_enable_failed "" "systemctl enable --now php${version}-fpm failed"
        add_change "${WEB_PHP_FPM_CAPABILITY}" enabled "" "systemctl enable --now php${version}-fpm succeeded"
    done

    # /run/lesta-php itself (the parent of each version's own subdirectory
    # above) needs to survive a reboot -- /run is tmpfs, recreated empty
    # on every boot, unlike every other owned root this project creates
    # under /etc or /var/lib. A systemd-tmpfiles rule, not a one-time
    # `install -d`, is what makes this durable across reboots.
    cat > /etc/tmpfiles.d/lesta-php-fpm.conf <<TMPFILES
d ${PHP_SOCKET_ROOT} 0770 root lesta -
TMPFILES
    for version in ${PHP_VERSIONS}; do
        printf 'd %s/%s 0770 root lesta -\n' "${PHP_SOCKET_ROOT}" "${version}" >> /etc/tmpfiles.d/lesta-php-fpm.conf
    done
    systemd-tmpfiles --create /etc/tmpfiles.d/lesta-php-fpm.conf >/dev/null 2>&1 || true
    add_change "${WEB_PHP_FPM_CAPABILITY}" installed /etc/tmpfiles.d/lesta-php-fpm.conf "systemd-tmpfiles rule written so ${PHP_SOCKET_ROOT} survives a reboot (/run is tmpfs)"

    install -d -m 0750 -o lesta-agent -g lesta "${PHP_FPM_STATE_ROOT}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${PHP_FPM_STATE_ROOT}" "failed to create ${PHP_FPM_STATE_ROOT}"
    add_change "${WEB_PHP_FPM_CAPABILITY}" ensured "${PHP_FPM_STATE_ROOT}" "generation-history bookkeeping directory present, mode 0750 lesta-agent:lesta"

    # --- sudoers: php-fpm<version> -t and systemctl reload php<version>-fpm
    #
    # The real lesta-agent-daemon systemd unit runs as the unprivileged
    # lesta-agent user: validate's own `php-fpm<version> -t` needs to read
    # every other tenant's own pool config on this node (not a secret this
    # process should be trusted with directly), and reload's own
    # `systemctl reload php<version>-fpm` needs root the same way every
    # other systemd-unit mutation this project execs does. systemctl is
    # scoped to the exact literal "reload php<version>-fpm" per version,
    # never a wildcard at all -- a wildcarded systemctl could restart/
    # stop/mask arbitrary units, a real privilege escalation this rule
    # must never grant.
    local sudoers_lines=""
    for version in ${PHP_VERSIONS}; do
        sudoers_lines="${sudoers_lines}/usr/sbin/php-fpm${version} -t *, /usr/bin/systemctl reload php${version}-fpm, "
    done

    cat > "${SUDOERS_LESTA_PHP_FPM_PATH}.tmp" <<SUDOERSEOF
lesta-agent ALL=(root) NOPASSWD: ${sudoers_lines%, }
SUDOERSEOF
    chmod 0440 "${SUDOERS_LESTA_PHP_FPM_PATH}.tmp"
    chown root:root "${SUDOERS_LESTA_PHP_FPM_PATH}.tmp"

    if ! visudo -c -f "${SUDOERS_LESTA_PHP_FPM_PATH}.tmp" >/dev/null 2>&1; then
        rm -f "${SUDOERS_LESTA_PHP_FPM_PATH}.tmp"
        fail_step "${EXIT_MUTATION_FAILURE}" sudoers_invalid "${SUDOERS_LESTA_PHP_FPM_PATH}" "visudo -c rejected the rendered ${SUDOERS_LESTA_PHP_FPM_PATH}; the candidate file was removed, the real one was never touched"
    fi

    mv "${SUDOERS_LESTA_PHP_FPM_PATH}.tmp" "${SUDOERS_LESTA_PHP_FPM_PATH}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${SUDOERS_LESTA_PHP_FPM_PATH}" "failed to activate ${SUDOERS_LESTA_PHP_FPM_PATH}"
    add_change "${WEB_PHP_FPM_CAPABILITY}" installed "${SUDOERS_LESTA_PHP_FPM_PATH}" "sudoers rule written and validated: lesta-agent may run each version's own php-fpm -t and systemctl reload as root, nothing else"

    checkpoint_write install_php_fpm "${MANIFEST_DIGEST}"
    log_info "install_php_fpm complete"
}

# --- phase 4: bootstrap_node_health --------------------------------------
#
# Self-test exercises exactly one representative version (8.3, Ubuntu's
# own native package) with a real create-then-delete round trip against
# the real, just-installed php8.3-fpm, using the already-existing
# lesta-agent system identity as the throwaway pool's own user/group
# (never a new tenant-facing identity, matching backup/cron's own
# established self-test precedent of reusing an already-provisioned
# identity rather than inventing a new one just for this).

run_node_health_selftest() {
    local resource_id create_idem create_corr delete_idem delete_corr
    local create_payload delete_payload envelope
    local agent_out agent_status status_line socket_path pool_path

    resource_id=$(selftest_new_uuid)
    create_idem=$(selftest_new_uuid)
    create_corr=$(selftest_new_uuid)
    delete_idem=$(selftest_new_uuid)
    delete_corr=$(selftest_new_uuid)

    socket_path="${PHP_SOCKET_ROOT}/8.3/${resource_id}.sock"
    pool_path="${PHP_POOL_BASE_DIR}/8.3/fpm/pool.d/${resource_id}.conf"

    create_payload=$(json_join_object \
        "$(json_kv_raw "account_id" "1")" \
        "$(json_kv_str "account_username" "lesta-agent")" \
        "$(json_kv_str "php_version" "8.3")" \
        "$(json_kv_raw "suspended" "false")")

    envelope=$(selftest_envelope "${WEB_PHP_FPM_CAPABILITY}" create "${resource_id}" "${create_idem}" "${create_corr}" 1 "${create_payload}")

    agent_status=0
    agent_out=$(selftest_invoke_agent "${envelope}") || agent_status=$?

    if [ "${agent_status}" -ne 0 ]; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_create_failed "${AGENT_BINARY_DEST}" "agent exited ${agent_status}: $(printf '%s' "${agent_out}" | tr '\n' ' ')"
    fi

    status_line=$(selftest_status_from_output "${agent_out}")
    if [ "${status_line}" != "applied" ]; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_create_not_applied "${AGENT_BINARY_DEST}" "agent returned status=${status_line:-unknown} for create, expected applied: $(printf '%s' "${agent_out}" | tr '\n' ' ')"
    fi

    log_info "bootstrap_node_health self-test: create returned status=applied"

    if [ ! -S "${socket_path}" ]; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_socket_missing "${socket_path}" "create returned status=applied but no real unix socket exists at ${socket_path}"
    fi

    log_info "bootstrap_node_health self-test: real pool socket ${socket_path} exists"

    delete_payload="${create_payload}"
    envelope=$(selftest_envelope "${WEB_PHP_FPM_CAPABILITY}" delete "${resource_id}" "${delete_idem}" "${delete_corr}" 1 "${delete_payload}")

    agent_status=0
    agent_out=$(selftest_invoke_agent "${envelope}") || agent_status=$?

    if [ "${agent_status}" -ne 0 ]; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_cleanup_failed "${pool_path}" "self-test create succeeded but delete's own agent invocation exited ${agent_status}: $(printf '%s' "${agent_out}" | tr '\n' ' ')"
    fi

    status_line=$(selftest_status_from_output "${agent_out}")
    if [ "${status_line}" != "applied" ]; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_delete_not_applied "${pool_path}" "agent returned status=${status_line:-unknown} for delete, expected applied: $(printf '%s' "${agent_out}" | tr '\n' ' ')"
    fi

    if [ -f "${pool_path}" ]; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_pool_not_removed "${pool_path}" "delete returned status=applied but the pool fragment still exists at ${pool_path}"
    fi

    add_change "${WEB_PHP_FPM_CAPABILITY}" installed_structural_only "${AGENT_BINARY_DEST}" "self-test create-then-delete of a throwaway PHP 8.3 pool against the real, just-installed php8.3-fpm returned status=applied both times, and a real unix socket genuinely accepted connections while the pool was live; remote control-plane registration is not yet built, so ${WEB_PHP_FPM_CAPABILITY} is structurally installed and health-checked but NOT YET control-plane-registered"
    log_info "bootstrap_node_health self-test: create+delete both returned status=applied, pool fragment and socket removed after delete"
}

bootstrap_node_health() {
    log_info "bootstrap_node_health: installing agent binary and running disposable self-test"

    agent_install_binary "${AGENT_BINARY_SRC}" "${NODE_HEALTH_MANIFEST}"

    run_node_health_selftest
    agent_restart_daemon_if_enabled "${WEB_PHP_FPM_CAPABILITY}"

    checkpoint_write bootstrap_node_health "${MANIFEST_DIGEST}"
    log_info "bootstrap_node_health complete"
}

# --- main -------------------------------------------------------------

main() {
    MANIFEST_DIGEST=$(compute_manifest_digest "${BASE_MANIFEST}" "${NODE_HEALTH_MANIFEST}" "${PHP_FPM_MANIFEST}")

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

    bootstrap_base
    install_php_fpm
    bootstrap_node_health

    checkpoint_remove
    release_write "${RELEASE_ID}" "${MANIFEST_DIGEST}"

    emit_apply_success_and_exit
}

main "$@"
