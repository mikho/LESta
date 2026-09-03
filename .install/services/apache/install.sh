#!/bin/sh
# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/services/apache/install.sh
#
# Bootstrap installer for the third release web capability: takes a bare
# Ubuntu 24.04/26.04 node to a state where the Go agent's
# apacheProductionConfig() preconditions (agent/cmd/lesta-agent/main.go) are
# met and web.apache.v1 is structurally installed and health-checked, per
# .install/INSTALLER-CONTRACT.md.
#
# This mirrors bind9/install.sh's own overall structure closely: own
# main(), own trap, no orchestration of any other service. Unlike
# nginx/install.sh (which is "the web installer", dispatching to this
# script for --web-server apache/both), this script never dispatches to
# anything else -- a bare `apache/install.sh --apply --yes` run installs
# Apache and nothing more, exactly like bind9/install.sh installs bind9 and
# nothing more.
#
# One flag beyond bind9's flag-free shape: --web-profile apache|both,
# defaulting to "apache" when omitted, so the common standalone case needs
# no new flag at all. This exists because Apache's own preflight and
# firewall registration must behave differently depending on whether it
# expects to be the public listener (checks ports 80/443 are free of a
# conflicting web server package, exactly mirroring nginx/install.sh's own
# preflight_check_conflicting_packages shape but flagging nginx/lighttpd as
# the conflict instead of apache2/lighttpd) or a `both`-profile loopback
# backend (skips the 80/443 checks and the firewall port registration
# entirely, since nginx legitimately owns those ports in that profile; no
# port-free check is needed for the loopback-only backend port at all,
# since preflight_check_port_free's own loopback-skip logic never enforces
# loopback ports anyway).
#
# web.apache.v1 is reported as "structurally installed, not yet
# control-plane-registered", for the same reason nginx/install.sh's and
# bind9/install.sh's own node.health.v1/dns.bind9.v1 changes already
# document: no network transport exists yet between Laravel and the agent,
# so the self-test this script runs (bootstrap_node_health's create-then-
# delete of a throwaway resource against the real, just-installed apache2)
# is the most that can honestly be proven right now.
#
# --web-profile both also rewrites /etc/apache2/ports.conf to
# `Listen 127.0.0.1:8080` (replacing the package's own stock `Listen 80`)
# and disables the stock 000-default site (its own `<VirtualHost *:80>`
# would otherwise reference a port nothing Listens on anymore): apache2 is a
# loopback-only backend behind nginx in this profile and must never attempt
# to bind the public port 80 nginx already owns as this node's real public
# listener (verified directly: without this rewrite, `systemctl enable --now
# apache2` genuinely fails to bind once nginx already holds port 80,
# breaking the both profile outright, not just as a cosmetic gap).
# apache2.conf's own stock `Include ports.conf` line (present by default,
# untouched by this installer, which never writes to apache2.conf itself)
# is what makes this rewrite take effect without editing apache2.conf.
#
# write_web_profile therefore runs BEFORE bootstrap_node_health, not after:
# apacheProductionConfig() (agent/cmd/lesta-agent/main.go) reads
# /etc/lesta/web-profile to choose the same port this installer just
# configured apache2 to actually Listen on (8080 for "both", 80 otherwise),
# so the self-test's own real HTTP health check must see a web-profile file
# that already matches apache2's real, just-configured listener -- not the
# file's own absence, which would default the self-test's own agent
# invocation to port 80, a port apache2 no longer listens on at all in the
# both profile.
#
# apache2.conf's own `IncludeOptional /etc/apache2/lesta.d/*.conf` line is a
# documented manual operator prerequisite (see README.md's "Manual
# prerequisite" section): this script only ever reads apache2.conf, never
# writes to it. No placeholder fragment is needed here (unlike bind9's
# own bare `include`, Apache's own IncludeOptional tolerates a directory
# with zero matches, confirmed during Phase 9's own research), and no
# 00-lesta-modules.conf seeding is needed either --
# agent/internal/capability/apache/content.go's own ensureModulesFragment
# already writes that itself, unconditionally, on every Apply call from the
# Go capability, not from this installer.
set -eu

# --- constants -------------------------------------------------------------

SCRIPT_VERSION="1.0.0"
RELEASE_ID="2026.08.29"
WEB_APACHE_CAPABILITY="web.apache.v1"

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
# shellcheck source=../../lib/firewall.sh
. "${INSTALL_ROOT}/lib/firewall.sh"
# shellcheck source=../../lib/agent.sh
. "${INSTALL_ROOT}/lib/agent.sh"
# shellcheck source=../../lib/selftest.sh
. "${INSTALL_ROOT}/lib/selftest.sh"

BASE_MANIFEST="${INSTALL_ROOT}/base/manifest.json"
FIREWALL_MANIFEST="${INSTALL_ROOT}/services/firewall/manifest.json"
NODE_HEALTH_MANIFEST="${INSTALL_ROOT}/services/node-health/manifest.json"
APACHE_MANIFEST="${INSTALL_ROOT}/services/apache/manifest.json"

AGENT_BINARY_SRC="${REPO_ROOT}/agent/dist/lesta-agent-linux-amd64"

# APACHE_CONF_PATH/APACHE_LIVE_DIR: passed explicitly to lib/preflight.sh's
# capability-agnostic check_lesta_include_present, this installer's own
# equivalent to nginx/install.sh's own NGINX_CONF_PATH/NGINX_LIVE_DIR.
APACHE_CONF_PATH="/etc/apache2/apache2.conf"
APACHE_LIVE_DIR="/etc/apache2/lesta.d"

# ASIS_MODULE_PATH is mod_asis.so's fixed installation path under
# Debian/Ubuntu's apache2-bin package layout -- the exact same literal
# agent/internal/capability/apache/content.go hardcodes as asisModulePath.
# Sanity-checking it exists right after apt-get install here beats failing
# silently at first domain-creation time.
ASIS_MODULE_PATH="/usr/lib/apache2/modules/mod_asis.so"

# WEB_PROFILE_PATH is the one shared artifact both this installer and
# nginx/install.sh's own --web-server both orchestration write: a single
# trimmed line ("apache" or "both"), the mechanism apacheProductionConfig()
# reads at process start to pick Apache's own rendered-vhost listen port.
WEB_PROFILE_PATH="/etc/lesta/web-profile"

# CHECKPOINT_PATH/RELEASE_PATH: this installer's own paths, distinct from
# nginx's and bind9's own (see their own top comments for the rationale).
export CHECKPOINT_PATH="/var/lib/lesta/install/apache.checkpoint"
export RELEASE_PATH="/etc/lesta/apache-release"

# --- globals (all pre-declared for `set -u` safety) -------------------------

MODE=""
WEB_PROFILE=""
YES=0
RUN_ID=""
MANIFEST_DIGEST=""
CHANGES=""
ERRORS=""

# --- usage / argument parsing ----------------------------------------------

usage() {
    cat <<'USAGE' >&2
Usage: install.sh --dry-run|--apply|--version [--web-profile apache|both] [--yes] [--help]

  --dry-run                 Run preflight and report what would change. No mutation.
  --apply                   Apply the installer. Requires --yes.
  --version                 Print installer version and exit.
  --web-profile <profile>   apache (default): this node's own Apache owns
                            public ports 80/443 directly.
                            both: Apache is a LESta-owned loopback-only
                            backend behind nginx, which owns 80/443 instead;
                            this installer then skips its own port/package
                            preflight and never registers or opens 80/443
                            for Apache.
  --yes                     Required with --apply: non-interactive confirmation.
  --help                    Print this message.
USAGE
}

# fail_invocation <message> prints the message and usage to stderr, emits a
# failed result JSON on stdout, and exits EXIT_INVALID_INVOCATION. Used for
# every invalid-invocation case (unrecognized flag, missing mode, rejected
# --web-profile value, --apply without --yes).
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
            --web-profile)
                [ "$#" -ge 2 ] || fail_invocation "--web-profile requires a value"
                WEB_PROFILE="$2"
                shift 2
                ;;
            --web-profile=*)
                WEB_PROFILE="${1#--web-profile=}"
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

    WEB_PROFILE="${WEB_PROFILE:-apache}"

    case "${WEB_PROFILE}" in
        apache | both) ;;
        *)
            fail_invocation "--web-profile must be one of apache|both"
            ;;
    esac

    if [ "${MODE}" = "apply" ] && [ "${YES}" -ne 1 ]; then
        fail_invocation "--apply requires --yes"
    fi
}

# --- result accumulation / emission -----------------------------------------
#
# add_change/add_error/fail_step live in lib/result.sh.

# manifest_capabilities_required_json -> a JSON array of apache manifest's own
# depends_on entries.
manifest_capabilities_required_json() {
    local items item lines=""

    items=$(manifest_extract_array "${APACHE_MANIFEST}" "depends_on")

    while IFS= read -r item; do
        [ -n "${item}" ] || continue
        lines=$(append_line "${lines}" "$(json_str "${item}")")
    done <<ITEMS
${items}
ITEMS

    json_array_from_lines "${lines}"
}

# emit_result_and_exit <status> <exit_code>
# Prints the single final result JSON object (matching
# INSTALLER-CONTRACT.md's example shape and install-result.schema.json) to
# stdout, then exits with exit_code. This is the only thing this script ever
# prints to stdout.
emit_result_and_exit() {
    local status="$1" exit_code="$2" changes_json errors_json required_json provided_json result

    changes_json=$(json_array_from_lines "${CHANGES}")
    errors_json=$(json_array_from_lines "${ERRORS}")
    required_json=$(manifest_capabilities_required_json)
    provided_json=$(json_array_from_lines "$(json_str "${WEB_APACHE_CAPABILITY}")")

    result=$(json_join_object \
        "$(json_kv_str "schema_version" "1")" \
        "$(json_kv_str "installer" "lesta-bootstrap")" \
        "$(json_kv_str "service" "web")" \
        "$(json_kv_str "web_profile" "${WEB_PROFILE:-apache}")" \
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
    WEB_PROFILE="${WEB_PROFILE:-apache}"
    MODE="version"
    emit_result_and_exit ok "${EXIT_OK}"
}

emit_dry_run_result_and_exit() {
    local install_state
    install_state=$(preflight_classify_install_state)

    add_change base.os.v1 would_ensure /etc/lesta "base directories and lesta/lesta-agent identity would be created or verified; install-state classification: ${install_state}"

    if [ "${WEB_PROFILE}" = "both" ]; then
        add_change firewall.baseline.v1 would_skip "" "web-profile both: apache is a loopback-only backend behind nginx; no ports would be registered or opened for it"
    else
        add_change firewall.baseline.v1 would_apply "${NFT_TABLE_PATH}" "deny-by-default nftables table would be loaded and ${FIREWALL_UNIT_PATH} installed and enabled, unioned with any other service already registered on this node"
    fi

    add_change node.health.v1 would_install "${AGENT_BINARY_DEST}" "vendored agent binary would be checksum-verified and copied into place, then self-tested by creating and deleting a throwaway resource against the real, just-installed apache2"

    if [ "${WEB_PROFILE}" = "both" ]; then
        add_change web.apache.v1 would_install "" "apt-get install -y apache2 would run; ${ASIS_MODULE_PATH} would be verified present; www-data would be added to the lesta group and apache2's AppArmor profile extended, so it can read /var/lib/lesta/apache at request time; ${APACHE_LIVE_DIR} and /var/lib/lesta/apache would be created; /etc/apache2/ports.conf would be rewritten to 'Listen 127.0.0.1:8080' only and the stock 000-default site disabled (apache is a loopback-only backend behind nginx); apache2 would be validated, enabled, and health-probed on 127.0.0.1:8080; ${WEB_PROFILE_PATH} would be written with 'both'"
    else
        add_change web.apache.v1 would_install "" "apt-get install -y apache2 would run; ${ASIS_MODULE_PATH} would be verified present; www-data would be added to the lesta group and apache2's AppArmor profile extended, so it can read /var/lib/lesta/apache at request time; ${APACHE_LIVE_DIR} and /var/lib/lesta/apache would be created; apache2 would be validated, enabled, and health-probed on 127.0.0.1:80; ${WEB_PROFILE_PATH} would be written with 'apache'"
    fi

    emit_result_and_exit would_change "${EXIT_OK}"
}

emit_apply_success_and_exit() {
    log_info "install.sh apply completed successfully"
    emit_result_and_exit applied "${EXIT_OK}"
}

# --- preflight orchestration --------------------------------------------

# preflight_check_include_line wraps the shared check_lesta_include_present
# with the add_error call and remediation text specific to apache2.conf.
# "Include" (capital) is passed as the keyword, not "include": Apache's own
# convention capitalizes its Include/IncludeOptional directives, and
# agent/internal/capability/apache/validate.go's own buildSyntheticConfig
# detects the line via a case-sensitive strings.Contains(line, "Include") --
# passing the same keyword here keeps this installer and the running agent
# from ever disagreeing about whether the precondition holds.
preflight_check_include_line() {
    local status=0

    check_lesta_include_present "${APACHE_CONF_PATH}" "${APACHE_LIVE_DIR}/*.conf" "Include" || status=$?

    case "${status}" in
        0)
            return 0
            ;;
        1)
            add_error apache_conf_missing "apache2.conf not found at ${APACHE_CONF_PATH}; install apache2 first (apt-get install -y apache2), then add this line inside it: IncludeOptional ${APACHE_LIVE_DIR}/*.conf -- do not remove any other existing Include/IncludeOptional lines. This installer never writes to apache2.conf itself." "${APACHE_CONF_PATH}"
            return 1
            ;;
        *)
            add_error apache_conf_missing_include "${APACHE_CONF_PATH} exists but has no IncludeOptional ${APACHE_LIVE_DIR}/*.conf line. Add that exact line by hand -- do not remove any other existing Include/IncludeOptional lines. This installer never writes to apache2.conf itself." "${APACHE_CONF_PATH}"
            return 1
            ;;
    esac
}

# preflight_check_conflicting_web_packages mirrors lib/preflight.sh's shared
# preflight_check_conflicting_packages shape exactly, but flags the opposite
# pair: nginx and lighttpd. Only ever run for --web-profile apache (Apache is
# about to become the public listener on 80/443 directly); never run for
# --web-profile both, where nginx legitimately already owns (or is about to
# own) those same ports as this node's actual public listener -- that is the
# both profile's own design, not a conflict.
preflight_check_conflicting_web_packages() {
    if dpkg -l 2>/dev/null | grep -E '^ii[[:space:]]+(nginx|lighttpd)\b' >/dev/null 2>&1; then
        add_error conflicting_package "nginx or lighttpd is already installed (dpkg -l); this installer refuses to displace an existing web server package" ""
        return 1
    fi

    if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet nginx 2>/dev/null; then
        add_error conflicting_service "nginx.service is active; this installer refuses to displace a running web server" ""
        return 1
    fi

    return 0
}

run_preflight() {
    local os_id os_version_id arch supported dir port failed=0

    if [ "$(id -u)" -ne 0 ]; then
        add_error not_root "install.sh must run as root (uid 0)" ""
        emit_result_and_exit failed "${EXIT_UNSUPPORTED_PLATFORM}"
    fi

    os_id=$(preflight_os_release_field ID)
    os_version_id=$(preflight_os_release_field VERSION_ID)
    supported=$(manifest_extract_array "${APACHE_MANIFEST}" "supported_ubuntu")

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

    if [ "${WEB_PROFILE}" = "apache" ]; then
        while IFS= read -r port; do
            [ -n "${port}" ] || continue
            preflight_check_port_free "${port}" tcp apache2 || failed=1
        done <<PORTS
$(manifest_extract_ports "${APACHE_MANIFEST}")
PORTS
        preflight_check_conflicting_web_packages || failed=1
    else
        log_info "web-profile both: apache is a loopback-only backend behind nginx; port/package preflight for 80/443 is skipped entirely (nginx legitimately owns them)"
    fi

    preflight_check_lesta_identity || failed=1
    preflight_check_include_line || failed=1

    if [ "${failed}" -ne 0 ]; then
        emit_result_and_exit failed "${EXIT_PREFLIGHT_CONFLICT}"
    fi

    log_info "preflight passed"
}

# --- phase 1: bootstrap_base -------------------------------------------
#
# Identical to nginx/install.sh's and bind9/install.sh's own bootstrap_base
# (capability-agnostic: creates the lesta group, lesta-agent user, and the
# three base directories, then refreshes CA certs). Duplicated rather than
# shared via a new lib file, matching the existing precedent.

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
    install -d -m 0750 -o root -g lesta /var/lib/lesta || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta "failed to create /var/lib/lesta"
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
# Shared (lib/firewall.sh). Only invoked from main() when WEB_PROFILE is
# "apache": in the "both" profile, apache is a loopback-only backend and
# nothing of its own needs a public port opened, so this whole phase (and
# its own firewall_register_service_ports call) is skipped outright rather
# than registering an empty/irrelevant fragment.

# --- phase 3: install_apache ---------------------------------------------

# apache_package_provenance_note <installed_version> -> a short "what
# actually happened" note, mirroring nginx/install.sh's and bind9/install.sh's
# own *_package_provenance_note exactly (same cached-.deb-sha256-or-version-
# string reasoning, just against apache2's own package name).
apache_package_provenance_note() {
    local version="$1" deb_path deb_sha

    deb_path=$(find /var/cache/apt/archives -maxdepth 1 -name "apache2_${version}_*.deb" -print -quit 2>/dev/null || true)

    if [ -n "${deb_path}" ] && [ -f "${deb_path}" ]; then
        deb_sha=$(compute_sha256 "${deb_path}")
        printf 'cached package %s sha256=%s' "$(basename "${deb_path}")" "${deb_sha}"
    else
        printf 'apt cache already cleared; recording version string only (%s)' "${version}"
    fi
}

# apache_listen_port -> the port install_apache configures apache2 to
# actually Listen on for the current WEB_PROFILE: 8080 (loopback-only) for
# "both", 80 (apache2's own package default, from its stock ports.conf,
# untouched by this installer for this profile) otherwise. The single
# source of truth both install_apache's own ports.conf rewrite and
# apache_health_probe read, so they can never disagree.
apache_listen_port() {
    if [ "${WEB_PROFILE}" = "both" ]; then
        printf '8080'
    else
        printf '80'
    fi
}

# apache_health_probe <port> -> a plain TCP-connect probe against
# 127.0.0.1:<port>, preferring curl, falling back to nc, falling back to a
# bash /dev/tcp connect. Mirrors nginx_health_probe's own fallback chain
# exactly.
apache_health_probe() {
    local port="$1"

    if command -v curl >/dev/null 2>&1; then
        curl -fsS --max-time 5 -o /dev/null "http://127.0.0.1:${port}/" 2>/dev/null
        return $?
    fi

    if command -v nc >/dev/null 2>&1; then
        nc -z -w 5 127.0.0.1 "${port}"
        return $?
    fi

    if command -v bash >/dev/null 2>&1; then
        bash -c "exec 3<>/dev/tcp/127.0.0.1/${port}" 2>/dev/null
        return $?
    fi

    return 1
}

install_apache() {
    log_info "install_apache: installing apache2 package and activating web.apache.v1"

    local out installed_version deb_note include_status=0 listen_port apache_apparmor_local

    if ! out=$(apt-get install -y apache2 2>&1); then
        add_error apt_install_failed "$(printf '%s' "${out}" | tr '\n' ' ')" ""
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi

    installed_version=$(dpkg-query -W -f='${Version}' apache2 2>/dev/null || true)
    if [ -z "${installed_version}" ]; then
        fail_step "${EXIT_MUTATION_FAILURE}" apt_install_unverifiable "" "dpkg-query could not report an installed apache2 version after apt-get install"
    fi

    deb_note=$(apache_package_provenance_note "${installed_version}")
    add_change web.apache.v1 installed "" "apt-get install -y apache2 succeeded; dpkg-query reports version ${installed_version}. ${deb_note}"

    if [ ! -f "${ASIS_MODULE_PATH}" ]; then
        fail_step "${EXIT_VERIFICATION_FAILURE}" mod_asis_missing "${ASIS_MODULE_PATH}" "expected mod_asis.so at the fixed path agent/internal/capability/apache/content.go hardcodes, but it is not present after apt-get install -y apache2; failing fast here beats failing silently at first domain-creation time"
    fi
    add_change web.apache.v1 verified "${ASIS_MODULE_PATH}" "mod_asis.so present at the fixed path the Go capability hardcodes"

    # apache2's worker processes run as APACHE_RUN_USER (www-data on Ubuntu,
    # see /etc/apache2/envvars), which apt-get install just created -- it has
    # no reason to already be a member of the lesta group. Unlike nginx's
    # worker (which never reads anything under its own state root at request
    # time: its content is a `return` directive baked directly into the
    # already-parsed vhost .conf), Apache's own mod_asis-served content lives
    # in a real file under /var/lib/lesta/apache (0750 root:lesta) that
    # www-data itself must open at request time, not just the agent. This is
    # the exact same class of gap bind9/install.sh's own installation of
    # named already hit and fixed (see its own comment for the full
    # reasoning): a Unix group grant alone is not sufficient by itself, since
    # Ubuntu's apache2 package also ships an AppArmor profile
    # (/etc/apparmor.d/usr.sbin.apache2) that denies anything outside its own
    # allow-list under AppArmor's default-deny model, independent of (and in
    # addition to) the Unix group grant. Both gaps are fixed here together,
    # by direct analogy rather than by guessing one at a time.
    usermod -aG lesta www-data || fail_step "${EXIT_MUTATION_FAILURE}" usermod_failed "" "usermod -aG lesta www-data failed"
    add_change web.apache.v1 group_membership_granted "" "www-data added to the lesta group, so apache2's worker processes can traverse /var/lib/lesta/apache to read mod_asis content"

    apache_apparmor_local="/etc/apparmor.d/local/usr.sbin.apache2"
    if [ -f "${apache_apparmor_local}" ]; then
        if ! grep -qF "/var/lib/lesta/apache/" "${apache_apparmor_local}" 2>/dev/null; then
            {
                printf '\n# Added by LESta: apache2 must read its own mod_asis content here.\n'
                printf '/var/lib/lesta/apache/ r,\n'
                printf '/var/lib/lesta/apache/** r,\n'
            } >> "${apache_apparmor_local}" || fail_step "${EXIT_MUTATION_FAILURE}" apparmor_override_write_failed "${apache_apparmor_local}" "failed to append the /var/lib/lesta/apache allowance to ${apache_apparmor_local}"
            add_change web.apache.v1 apparmor_extended "${apache_apparmor_local}" "granted apache2 read access to /var/lib/lesta/apache via Ubuntu's own local-override include point"
        fi

        # A second, separate grant path from the one just above: that one
        # covers www-data's own worker processes reading mod_asis content at
        # request time. This one covers apache2's ROOT master process itself,
        # which reads SSLCertificateFile/SSLCertificateKeyFile at
        # config-parse/reload time, before any privilege drop to www-data --
        # tls.acme.v1 writes those files (fullchain.pem 0644, privkey.pem
        # 0600) under /var/lib/lesta/acme/certs/<domain>/, a root-owned tree
        # apache2's AppArmor profile has no reason to already allow into.
        # Without this, a domain's own default_ssl.conf.tmpl vhost (see
        # agent/internal/capability/apache/templates) would fail to reload
        # under AppArmor enforcement even though the files themselves are
        # readable by their own Unix permissions.
        if ! grep -qF "/var/lib/lesta/acme/certs/" "${apache_apparmor_local}" 2>/dev/null; then
            {
                printf '\n# Added by LESta: apache2'\''s root master process must read issued certificates here.\n'
                printf '/var/lib/lesta/acme/certs/ r,\n'
                printf '/var/lib/lesta/acme/certs/** r,\n'
            } >> "${apache_apparmor_local}" || fail_step "${EXIT_MUTATION_FAILURE}" apparmor_override_write_failed "${apache_apparmor_local}" "failed to append the /var/lib/lesta/acme/certs allowance to ${apache_apparmor_local}"
            add_change web.apache.v1 apparmor_extended "${apache_apparmor_local}" "granted apache2 read access to /var/lib/lesta/acme/certs via Ubuntu's own local-override include point"
        fi

        if command -v apparmor_parser >/dev/null 2>&1; then
            apparmor_parser -r /etc/apparmor.d/usr.sbin.apache2 || fail_step "${EXIT_MUTATION_FAILURE}" apparmor_reload_failed "${apache_apparmor_local}" "apparmor_parser -r /etc/apparmor.d/usr.sbin.apache2 failed"
            add_change web.apache.v1 apparmor_reloaded "" "apparmor_parser -r reloaded the apache2 profile with the /var/lib/lesta/apache allowance"
        fi
    else
        log_info "AppArmor local override file for apache2 not present (${apache_apparmor_local}); assuming AppArmor is not enforcing apache2 on this host, skipping"
    fi

    install -d -m 0755 "${APACHE_LIVE_DIR}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${APACHE_LIVE_DIR}" "failed to create ${APACHE_LIVE_DIR}"
    install -d -m 0750 -o root -g lesta /var/lib/lesta/apache || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta/apache "failed to create /var/lib/lesta/apache"
    add_change web.apache.v1 ensured "${APACHE_LIVE_DIR}" "include directory present, mode 0755"
    add_change web.apache.v1 ensured /var/lib/lesta/apache "state directory present, mode 0750 root:lesta"

    if [ "${WEB_PROFILE}" = "both" ]; then
        # apache2.conf's own stock `Include ports.conf` line (untouched by
        # this installer) is what makes this rewrite take effect: apache2 is
        # a loopback-only backend here and must never attempt to bind the
        # public port 80 nginx already owns as this node's real public
        # listener. The stock 000-default site's own <VirtualHost *:80>
        # would otherwise reference a port nothing Listens on anymore, so it
        # is disabled too.
        printf 'Listen 127.0.0.1:8080\n' > /etc/apache2/ports.conf || fail_step "${EXIT_MUTATION_FAILURE}" ports_conf_write_failed /etc/apache2/ports.conf "failed to rewrite ports.conf for the both profile"
        add_change web.apache.v1 reconfigured /etc/apache2/ports.conf "rewrote ports.conf to 'Listen 127.0.0.1:8080' only: apache is a loopback-only backend behind nginx in the both profile"

        a2dissite 000-default >/dev/null 2>&1 || true
        add_change web.apache.v1 disabled_default_site "" "disabled apache2's own stock 000-default site (best-effort; harmless if already disabled or absent)"
    fi

    check_lesta_include_present "${APACHE_CONF_PATH}" "${APACHE_LIVE_DIR}/*.conf" "Include" || include_status=$?
    if [ "${include_status}" -ne 0 ]; then
        fail_step "${EXIT_PREFLIGHT_CONFLICT}" apache_conf_include_missing "${APACHE_CONF_PATH}" "the lesta.d include line disappeared between preflight and this defensive re-check; investigate concurrent apache2.conf edits"
    fi

    # apache2ctl configtest, not a bare `apache2 -t`: real apache2.conf
    # references ${APACHE_RUN_DIR} and friends (e.g. via DefaultRuntimeDir),
    # which are only ever defined by sourcing /etc/apache2/envvars first --
    # normally apache2ctl's own job, since it is the one path every other
    # invocation in this script (apt's postinst, systemctl's own unit file)
    # already goes through. A bare `apache2 -t` skips that sourcing entirely
    # and fails outright with "AH00111: Config variable ${APACHE_RUN_DIR} is
    # not defined ... DefaultRuntimeDir must be a valid directory", caught
    # via a real --apply run in CI. This is unrelated to the Go agent's own
    # explicit Config.Env list (agent/cmd/lesta-agent/main.go): that list
    # covers the agent's own later exec.Command calls against the raw
    # apache2 binary, a completely separate code path from this installer's
    # own one-time validation call.
    if ! out=$(apache2ctl configtest 2>&1); then
        add_error apache_test_failed "$(printf '%s' "${out}" | tr '\n' ' ')" "${APACHE_CONF_PATH}"
        emit_result_and_exit failed "${EXIT_HEALTH_FAILURE}"
    fi
    add_change web.apache.v1 validated "" "apache2ctl configtest passed"

    systemctl enable apache2 || fail_step "${EXIT_HEALTH_FAILURE}" systemctl_enable_failed "" "systemctl enable apache2 failed"

    # A restart, not `enable --now`: the apache2 package's own postinst
    # starts apache2 automatically during `apt-get install` above, before
    # the `usermod -aG lesta www-data` call earlier in this function ever
    # ran (in the standalone profile, that auto-start succeeds outright; in
    # the both profile, it fails to bind the public port nginx already owns,
    # but the point stands either way -- postinst always attempts to start
    # it first). Unix supplementary groups are fixed at process-exec time
    # (via initgroups, when a worker forks under APACHE_RUN_USER) and are
    # never re-read from /etc/group while a process is already running;
    # `enable --now` is a no-op on an already-active unit and would never
    # replace that pre-group-grant process. Mirrors bind9/install.sh's own
    # identical fix for named/bind, verified there directly against CI (see
    # its own comment for the full account) rather than re-discovered here
    # by trial and error. An unconditional restart guarantees a fresh exec
    # that picks up the just-granted group, whether apache2 was already
    # running from the postinst or not started yet.
    if ! out=$(systemctl restart apache2 2>&1); then
        add_error apache_restart_failed "$(printf '%s' "${out}" | tr '\n' ' ')" ""
        emit_result_and_exit failed "${EXIT_HEALTH_FAILURE}"
    fi
    add_change web.apache.v1 enabled "" "systemctl enable apache2 + systemctl restart apache2 succeeded"

    listen_port=$(apache_listen_port)
    apache_health_probe "${listen_port}" || fail_step "${EXIT_HEALTH_FAILURE}" apache_health_check_failed "" "apache2 did not answer a health probe on 127.0.0.1:${listen_port} after enable + restart"
    add_change web.apache.v1 healthy "" "TCP health probe against 127.0.0.1:${listen_port} succeeded"

    checkpoint_write install_apache "${MANIFEST_DIGEST}"
    log_info "install_apache complete"
}

# --- phase 4: write_web_profile --------------------------------------------
#
# Deliberately run BEFORE bootstrap_node_health (see this file's own top
# comment for why the ordering matters): writes WEB_PROFILE_PATH with
# exactly "apache" or "both", the mechanism apacheProductionConfig()
# (agent/cmd/lesta-agent/main.go) reads at process start to pick Apache's
# own rendered-vhost listen port (8080 for "both", 80 otherwise) -- the same
# port install_apache's own ports.conf rewrite just configured apache2 to
# actually Listen on, so bootstrap_node_health's own self-test targets a
# port that is genuinely live. A --web-server both run of nginx/install.sh
# invokes this script with --web-profile both, so this overwrites the plain
# "apache" value a standalone run would have written, matching the
# immutable-after-bootstrap decision on record (ADR 0002 Decision 6): the
# profile is chosen once, at this single apply, never migrated later.
write_web_profile() {
    printf '%s' "${WEB_PROFILE}" > "${WEB_PROFILE_PATH}.tmp" || fail_step "${EXIT_MUTATION_FAILURE}" web_profile_write_failed "${WEB_PROFILE_PATH}" "failed to write ${WEB_PROFILE_PATH}.tmp"
    chmod 0644 "${WEB_PROFILE_PATH}.tmp"
    mv -f "${WEB_PROFILE_PATH}.tmp" "${WEB_PROFILE_PATH}" || fail_step "${EXIT_MUTATION_FAILURE}" web_profile_write_failed "${WEB_PROFILE_PATH}" "failed to rename ${WEB_PROFILE_PATH}.tmp into place"
    add_change web.apache.v1 web_profile_written "${WEB_PROFILE_PATH}" "wrote '${WEB_PROFILE}' so the agent's apacheProductionConfig() can select the correct listen port at process start (both -> 8080; apache -> 80)"

    checkpoint_write write_web_profile "${MANIFEST_DIGEST}"
    log_info "write_web_profile complete"
}

# --- phase 5: bootstrap_node_health --------------------------------------
#
# selftest_new_uuid/selftest_envelope/selftest_invoke_agent/
# selftest_status_from_output/run_node_health_selftest_delete live in
# lib/selftest.sh, shared with nginx/install.sh's and bind9/install.sh's own
# self-tests.

# run_node_health_selftest feeds two real OperationEnvelopes, a `create` then
# a `delete`, to the just-placed agent binary, targeting the exact real
# production paths (APACHE_LIVE_DIR, /var/lib/lesta/apache,
# /etc/apache2/apache2.conf, the real system apache2 service install_apache
# just enabled). The resource is a throwaway domain (selftest.lesta.invalid)
# that exists only for the duration of this function: created, verified
# applied, then deleted, leaving no residue. The payload shape is identical
# to nginx/install.sh's own self-test payload (apache.Payload mirrors
# nginx.Payload exactly): {domain, aliases: [], ip_address, web_template:
# "default", ssl: {mode: "off"}, suspended: false}.
run_node_health_selftest() {
    local resource_id create_idem create_corr delete_idem delete_corr
    local ssl_obj payload envelope agent_out agent_status status_line

    resource_id=$(selftest_new_uuid)
    create_idem=$(selftest_new_uuid)
    create_corr=$(selftest_new_uuid)
    delete_idem=$(selftest_new_uuid)
    delete_corr=$(selftest_new_uuid)

    ssl_obj=$(json_join_object "$(json_kv_str "mode" "off")")
    payload=$(json_join_object \
        "$(json_kv_str "domain" "selftest.lesta.invalid")" \
        "$(json_kv_raw "aliases" "[]")" \
        "$(json_kv_str "ip_address" "127.0.0.1")" \
        "$(json_kv_str "web_template" "default")" \
        "$(json_kv_raw "ssl" "${ssl_obj}")" \
        "$(json_kv_raw "suspended" "false")")

    envelope=$(selftest_envelope "${WEB_APACHE_CAPABILITY}" create "${resource_id}" "${create_idem}" "${create_corr}" 1 "${payload}")

    agent_status=0
    agent_out=$(selftest_invoke_agent "${envelope}") || agent_status=$?

    if [ "${agent_status}" -ne 0 ]; then
        add_error selftest_create_failed "agent exited ${agent_status}: $(printf '%s' "${agent_out}" | tr '\n' ' ')" "${AGENT_BINARY_DEST}"
        emit_result_and_exit failed "${EXIT_HEALTH_FAILURE}"
    fi

    status_line=$(selftest_status_from_output "${agent_out}")
    if [ "${status_line}" != "applied" ]; then
        add_error selftest_create_not_applied "agent returned status=${status_line:-unknown} for create, expected applied: $(printf '%s' "${agent_out}" | tr '\n' ' ')" "${AGENT_BINARY_DEST}"
        run_node_health_selftest_delete "${WEB_APACHE_CAPABILITY}" "${resource_id}" "${payload}" "${delete_idem}" "${delete_corr}" || true
        emit_result_and_exit failed "${EXIT_HEALTH_FAILURE}"
    fi

    log_info "bootstrap_node_health self-test: create returned status=applied"

    if ! run_node_health_selftest_delete "${WEB_APACHE_CAPABILITY}" "${resource_id}" "${payload}" "${delete_idem}" "${delete_corr}"; then
        add_error selftest_cleanup_failed "self-test create succeeded but the throwaway resource could not be deleted afterward" "${APACHE_LIVE_DIR}"
        emit_result_and_exit failed "${EXIT_HEALTH_FAILURE}"
    fi

    add_change web.apache.v1 installed_structural_only "${AGENT_BINARY_DEST}" "self-test create-then-delete of a throwaway resource (selftest.lesta.invalid) against the real, just-installed apache2 returned status=applied both times; remote control-plane registration is not yet built, so web.apache.v1 is structurally installed and health-checked but NOT YET control-plane-registered"
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
    MANIFEST_DIGEST=$(compute_manifest_digest "${BASE_MANIFEST}" "${FIREWALL_MANIFEST}" "${NODE_HEALTH_MANIFEST}" "${APACHE_MANIFEST}")

    parse_args "$@"
    validate_args

    if [ "${MODE}" = "version" ]; then
        emit_version_and_exit
    fi

    RUN_ID=$(run_generate_id)
    run_install_cleanup_trap

    # No mutation happens before this point, matching nginx/install.sh's and
    # bind9/install.sh's own ordering: log_init itself would create a
    # directory, and ensure_lesta_group would run groupadd, so both wait
    # until after run_preflight has passed.
    log_info "starting install.sh mode=${MODE} web_profile=${WEB_PROFILE} run_id=${RUN_ID} installer_version=${SCRIPT_VERSION}"

    run_preflight

    if [ "${MODE}" = "dry-run" ]; then
        emit_dry_run_result_and_exit
    fi

    # --apply, and preflight passed: only now is any mutation permitted.
    ensure_lesta_group
    log_init
    log_info "preflight passed; beginning apply mutations"

    bootstrap_base

    if [ "${WEB_PROFILE}" = "apache" ]; then
        bootstrap_firewall_baseline apache "${APACHE_MANIFEST}"
        checkpoint_write bootstrap_firewall_baseline "${MANIFEST_DIGEST}"
    else
        log_info "web-profile both: skipping firewall port registration entirely (apache is a loopback-only backend; nginx owns 80/443)"
    fi

    install_apache
    write_web_profile
    bootstrap_node_health

    checkpoint_remove
    release_write "${RELEASE_ID}" "${MANIFEST_DIGEST}"

    emit_apply_success_and_exit
}

main "$@"
