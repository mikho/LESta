#!/bin/sh
# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/services/nginx/install.sh
#
# Bootstrap installer for the first release web capability: takes a bare
# Ubuntu 24.04/26.04 node to a state where the Go agent's productionConfig()
# preconditions (agent/cmd/lesta-agent/main.go) are met and web.nginx.v1 is
# structurally installed and health-checked, per
# .install/INSTALLER-CONTRACT.md.
#
# This is one cohesive script, not a generic manifest-driven runner: it
# inline-handles base/firewall/node-health/nginx as four named phases
# (bootstrap_base, bootstrap_firewall_baseline, bootstrap_node_health,
# install_nginx), each idempotent and touching only its own manifest's
# owned_roots. A later extraction into standalone per-service scripts would
# be mechanical, since every phase is already named after the capability it
# satisfies.
#
# node.health.v1 is reported as "structurally installed, not yet
# control-plane-registered": no network transport exists yet between Laravel
# and the agent (an already-decided scope cut from a prior phase), so the
# self-test this script runs (bootstrap_node_health's create-then-delete of a
# throwaway resource against the real, just-installed nginx) is the most that
# can honestly be proven right now.
#
# bootstrap_node_health runs AFTER install_nginx, deliberately out of the
# order nginx/manifest.json's own depends_on lists (base, firewall,
# node-health, nginx): that array expresses capability-usability gating for a
# hypothetical generic runner, not this script's own internal step order. The
# self-test needs a real, running nginx to validate the agent binary against;
# testing it against the real production paths and the real system nginx
# install_nginx just enabled is a stronger proof than testing it earlier
# against a synthetic stand-in, and it requires no configurability in the
# shipped agent binary (see agent/cmd/lesta-agent/main.go's productionConfig()
# comment for why that matters).
#
# nginx.conf's own `include /etc/nginx/lesta.d/*.conf;` line is a documented
# manual operator prerequisite (see README.md's "Manual prerequisite"
# section): this script only ever reads nginx.conf, never writes to it.
set -eu

# --- constants -------------------------------------------------------------

SCRIPT_VERSION="1.0.0"
RELEASE_ID="2026.08.28"
WEB_NGINX_CAPABILITY="web.nginx.v1"

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
NGINX_MANIFEST="${INSTALL_ROOT}/services/nginx/manifest.json"

AGENT_BINARY_SRC="${REPO_ROOT}/agent/dist/lesta-agent-linux-amd64"

# NGINX_CONF_PATH/NGINX_LIVE_DIR: no longer exported from lib/preflight.sh
# (that file's own check_lesta_include_present is now capability-agnostic
# and takes these as explicit parameters instead).
NGINX_CONF_PATH="/etc/nginx/nginx.conf"
NGINX_LIVE_DIR="/etc/nginx/lesta.d"

# CHECKPOINT_PATH/RELEASE_PATH: no longer exported from lib/checkpoint.sh
# (each installer now sets its own). These are the exact same paths this
# installer has always used, preserved unchanged so a rerun after this
# refactor still finds an existing node's checkpoint/release files. Still
# exported here (only consumed ambiently by lib/checkpoint.sh's functions,
# a separate sourced file, never referenced by name in this file's own
# body) so a single-file shellcheck pass doesn't flag them as unused.
export CHECKPOINT_PATH="/var/lib/lesta/install/nginx.checkpoint"
export RELEASE_PATH="/etc/lesta/nginx-release"

# --- globals (all pre-declared for `set -u` safety) -------------------------

MODE=""
WEB_SERVER=""
YES=0
RUN_ID=""
MANIFEST_DIGEST=""
CHANGES=""
ERRORS=""

# --- usage / argument parsing ----------------------------------------------

usage() {
    cat <<'USAGE' >&2
Usage: install.sh --dry-run|--apply|--version --web-server nginx|apache|both [--yes] [--help]

  --dry-run                Run preflight and report what would change. No mutation.
  --apply                  Apply the installer. Requires --yes.
  --version                Print installer version and exit.
  --web-server <profile>   Required for --dry-run/--apply.
                           nginx:  installs nginx alone (unchanged from before).
                           apache: never installs nginx at all; delegates the
                                   entire run to apache/install.sh (Apache owns
                                   80/443 directly).
                           both:   installs nginx as the public listener (as
                                   above), then delegates to apache/install.sh
                                   --web-profile both for a loopback-only Apache
                                   backend behind it.
  --yes                    Required with --apply: non-interactive confirmation.
  --help                   Print this message.
USAGE
}

# fail_invocation <message> prints the message and usage to stderr, emits a
# failed result JSON on stdout, and exits EXIT_INVALID_INVOCATION. Used for
# every invalid-invocation case (unrecognized flag, missing mode, missing
# --web-server, rejected --web-server value, --apply without --yes).
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
            --web-server)
                [ "$#" -ge 2 ] || fail_invocation "--web-server requires a value"
                WEB_SERVER="$2"
                shift 2
                ;;
            --web-server=*)
                WEB_SERVER="${1#--web-server=}"
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

    case "${WEB_SERVER}" in
        nginx | apache | both) ;;
        "")
            fail_invocation "--web-server is required for --dry-run/--apply (one of nginx|apache|both)"
            ;;
        *)
            fail_invocation "--web-server must be one of nginx|apache|both"
            ;;
    esac

    if [ "${MODE}" = "apply" ] && [ "${YES}" -ne 1 ]; then
        fail_invocation "--apply requires --yes"
    fi
}

# --- result accumulation / emission -----------------------------------------
#
# add_change/add_error/fail_step now live in lib/result.sh (pure JSON-record
# builders with no nginx-specific behavior; relocated verbatim).

# manifest_capabilities_required_json -> a JSON array of nginx manifest's own
# depends_on entries.
manifest_capabilities_required_json() {
    local items item lines=""

    items=$(manifest_extract_array "${NGINX_MANIFEST}" "depends_on")

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
    provided_json=$(json_array_from_lines "$(json_str "${WEB_NGINX_CAPABILITY}")")

    result=$(json_join_object \
        "$(json_kv_str "schema_version" "1")" \
        "$(json_kv_str "installer" "lesta-bootstrap")" \
        "$(json_kv_str "service" "web")" \
        "$(json_kv_str "web_profile" "${WEB_SERVER:-nginx}")" \
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
    WEB_SERVER="${WEB_SERVER:-nginx}"
    MODE="version"
    emit_result_and_exit ok "${EXIT_OK}"
}

emit_dry_run_result_and_exit() {
    local install_state
    install_state=$(preflight_classify_install_state)

    add_change base.os.v1 would_ensure /etc/lesta "base directories and lesta/lesta-agent identity would be created or verified; install-state classification: ${install_state}"

    case "${WEB_SERVER}" in
        nginx)
            add_change firewall.baseline.v1 would_apply "${NFT_TABLE_PATH}" "deny-by-default nftables table would be loaded and ${FIREWALL_UNIT_PATH} installed and enabled"
            add_change node.health.v1 would_install "${AGENT_BINARY_DEST}" "vendored agent binary would be checksum-verified and copied into place, then self-tested by creating and deleting a throwaway resource against the real, just-installed nginx"
            add_change web.nginx.v1 would_install "" "apt-get install -y nginx would run; ${NGINX_LIVE_DIR} and /var/lib/lesta/nginx would be created; nginx would be enabled and health-probed"
            ;;
        both)
            add_change firewall.baseline.v1 would_apply "${NFT_TABLE_PATH}" "deny-by-default nftables table would be loaded and ${FIREWALL_UNIT_PATH} installed and enabled"
            add_change node.health.v1 would_install "${AGENT_BINARY_DEST}" "vendored agent binary would be checksum-verified and copied into place, then self-tested by creating and deleting a throwaway resource against the real, just-installed nginx"
            add_change web.nginx.v1 would_install "" "apt-get install -y nginx would run; ${NGINX_LIVE_DIR} and /var/lib/lesta/nginx would be created; nginx would be enabled and health-probed as this node's public listener"
            add_change web.apache.v1 would_delegate "${INSTALL_ROOT}/services/apache/install.sh" "after nginx is up, apache/install.sh --apply --yes --web-profile both would run: apache2 would be installed as a loopback-only backend (no public ports opened for it), health-probed, and /etc/lesta/web-profile would be written with 'both'"
            ;;
        apache)
            add_change web.nginx.v1 would_skip "" "nginx is never installed for --web-server apache; this run delegates entirely to apache/install.sh"
            add_change web.apache.v1 would_delegate "${INSTALL_ROOT}/services/apache/install.sh" "apache/install.sh --apply --yes would run: apache2 would be installed as the public listener on ports 80/443, health-probed, and /etc/lesta/web-profile would be written with 'apache'"
            ;;
    esac

    emit_result_and_exit would_change "${EXIT_OK}"
}

emit_apply_success_and_exit() {
    log_info "install.sh apply completed successfully"
    emit_result_and_exit applied "${EXIT_OK}"
}

# --- preflight orchestration --------------------------------------------

# preflight_check_include_line wraps the shared check_lesta_include_present
# with the add_error call and remediation text specific to nginx.conf.
preflight_check_include_line() {
    local status=0

    check_lesta_include_present "${NGINX_CONF_PATH}" "${NGINX_LIVE_DIR}/*.conf" "include" || status=$?

    case "${status}" in
        0)
            return 0
            ;;
        1)
            add_error nginx_conf_missing "nginx.conf not found at ${NGINX_CONF_PATH}; install nginx first (apt-get install -y nginx), then add this line inside its http {} block: include ${NGINX_LIVE_DIR}/*.conf; -- do not remove any other existing include lines. This installer never writes to nginx.conf itself." "${NGINX_CONF_PATH}"
            return 1
            ;;
        *)
            add_error nginx_conf_missing_include "${NGINX_CONF_PATH} exists but has no include ${NGINX_LIVE_DIR}/*.conf; line inside its http {} block. Add that exact line by hand -- do not remove any other existing include lines. This installer never writes to nginx.conf itself." "${NGINX_CONF_PATH}"
            return 1
            ;;
    esac
}

# preflight_check_conflicting_lighttpd is --web-server both's own narrower
# variant of the shared preflight_check_conflicting_packages
# (lib/preflight.sh): apache2 is EXPECTED in the both profile (this same
# --apply run's own later delegation to apache/install.sh installs it), so it
# is never treated as a conflict here -- unlike plain --web-server nginx,
# where an already-installed apache2 legitimately means "an operator-managed
# web server is already here, refuse to displace it". Without this
# narrower check, a --web-server both re-apply (an already-successful first
# run has apache2 installed and active) would wrongly fail its own second
# preflight forever. lighttpd remains a genuine, unrelated third-party
# competitor this profile never expects and never installs.
preflight_check_conflicting_lighttpd() {
    if dpkg -l 2>/dev/null | grep -E '^ii[[:space:]]+lighttpd\b' >/dev/null 2>&1; then
        add_error conflicting_package "lighttpd is already installed (dpkg -l); this installer refuses to displace an existing web server package" ""
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
    supported=$(manifest_extract_array "${NGINX_MANIFEST}" "supported_ubuntu")

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

    case "${WEB_SERVER}" in
        nginx | both)
            while IFS= read -r port; do
                [ -n "${port}" ] || continue
                preflight_check_port_free "${port}" tcp nginx || failed=1
            done <<PORTS
$(manifest_extract_ports "${NGINX_MANIFEST}")
PORTS

            if [ "${WEB_SERVER}" = "nginx" ]; then
                preflight_check_conflicting_packages || failed=1
            else
                preflight_check_conflicting_lighttpd || failed=1
            fi

            preflight_check_lesta_identity || failed=1
            preflight_check_include_line || failed=1
            ;;
        apache)
            log_info "web-server apache: nginx is never installed this run; this script's own preflight (ports, conflicting packages, nginx.conf include line) is skipped entirely -- apache/install.sh runs its own full preflight when this script delegates to it"
            preflight_check_lesta_identity || failed=1
            ;;
    esac

    if [ "${failed}" -ne 0 ]; then
        emit_result_and_exit failed "${EXIT_PREFLIGHT_CONFLICT}"
    fi

    log_info "preflight passed"
}

# --- phase 1: bootstrap_base -------------------------------------------

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
# bootstrap_firewall_baseline itself now lives in lib/firewall.sh, shared by
# every leaf-service installer: it renders the deny-by-default nftables
# table from the UNION of every service's own registered ports (each
# service's ports live in their own FIREWALL_PORTS_DIR/<service_id>.ports
# fragment), rather than nginx's own ports alone -- otherwise a second
# leaf-service installer's own firewall phase would silently replace this
# entire table and close nginx's ports, since `nft -f` replaces a named
# table's content rather than merging it. See lib/firewall.sh's own top
# comment for the full rationale. Checkpoint behavior stays here, per
# installer, unchanged.

# --- phase 3: install_nginx ---------------------------------------------

# nginx_package_provenance_note <installed_version> -> a short "what actually
# happened" note: the cached .deb's own sha256 when apt's cache still has it,
# or just the version string when the cache was cleared. This is a record of
# achieved state, not a pre-commitment: trust itself derives from APT's own
# Secure-APT GPG chain over the pinned Ubuntu archive, not from this note.
nginx_package_provenance_note() {
    local version="$1" deb_path deb_sha

    deb_path=$(find /var/cache/apt/archives -maxdepth 1 -name "nginx_${version}_*.deb" -print -quit 2>/dev/null || true)
    if [ -z "${deb_path}" ]; then
        deb_path=$(find /var/cache/apt/archives -maxdepth 1 -name "nginx-core_${version}_*.deb" -print -quit 2>/dev/null || true)
    fi

    if [ -n "${deb_path}" ] && [ -f "${deb_path}" ]; then
        deb_sha=$(compute_sha256 "${deb_path}")
        printf 'cached package %s sha256=%s' "$(basename "${deb_path}")" "${deb_sha}"
    else
        printf 'apt cache already cleared; recording version string only (%s)' "${version}"
    fi
}

# nginx_health_probe -> a plain TCP-connect probe against 127.0.0.1:80,
# preferring curl, falling back to nc, falling back to a bash /dev/tcp
# connect (dash itself has no /dev/tcp support, so bash is invoked explicitly
# just for this one fallback, only if present).
nginx_health_probe() {
    if command -v curl >/dev/null 2>&1; then
        curl -fsS --max-time 5 -o /dev/null http://127.0.0.1:80/ 2>/dev/null
        return $?
    fi

    if command -v nc >/dev/null 2>&1; then
        nc -z -w 5 127.0.0.1 80
        return $?
    fi

    if command -v bash >/dev/null 2>&1; then
        bash -c 'exec 3<>/dev/tcp/127.0.0.1/80' 2>/dev/null
        return $?
    fi

    return 1
}

install_nginx() {
    log_info "install_nginx: installing nginx package and activating web.nginx.v1"

    local out installed_version deb_note include_status=0

    if ! out=$(apt-get install -y nginx 2>&1); then
        add_error apt_install_failed "$(printf '%s' "${out}" | tr '\n' ' ')" ""
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi

    installed_version=$(dpkg-query -W -f='${Version}' nginx 2>/dev/null || true)
    if [ -z "${installed_version}" ]; then
        fail_step "${EXIT_MUTATION_FAILURE}" apt_install_unverifiable "" "dpkg-query could not report an installed nginx version after apt-get install"
    fi

    deb_note=$(nginx_package_provenance_note "${installed_version}")
    add_change web.nginx.v1 installed "" "apt-get install -y nginx succeeded; dpkg-query reports version ${installed_version}. ${deb_note}"

    install -d -m 0755 "${NGINX_LIVE_DIR}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${NGINX_LIVE_DIR}" "failed to create ${NGINX_LIVE_DIR}"
    install -d -m 0750 -o root -g lesta /var/lib/lesta/nginx || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta/nginx "failed to create /var/lib/lesta/nginx"
    add_change web.nginx.v1 ensured "${NGINX_LIVE_DIR}" "include directory present, mode 0755"
    add_change web.nginx.v1 ensured /var/lib/lesta/nginx "state directory present, mode 0750 root:lesta"

    check_lesta_include_present "${NGINX_CONF_PATH}" "${NGINX_LIVE_DIR}/*.conf" "include" || include_status=$?
    if [ "${include_status}" -ne 0 ]; then
        fail_step "${EXIT_PREFLIGHT_CONFLICT}" nginx_conf_include_missing "${NGINX_CONF_PATH}" "the lesta.d include line disappeared between preflight and this defensive re-check; investigate concurrent nginx.conf edits"
    fi

    if ! out=$(nginx -t 2>&1); then
        add_error nginx_test_failed "$(printf '%s' "${out}" | tr '\n' ' ')" "${NGINX_CONF_PATH}"
        emit_result_and_exit failed "${EXIT_HEALTH_FAILURE}"
    fi
    add_change web.nginx.v1 validated "" "nginx -t passed"

    systemctl enable --now nginx || fail_step "${EXIT_HEALTH_FAILURE}" systemctl_enable_failed "" "systemctl enable --now nginx failed"
    add_change web.nginx.v1 enabled "" "systemctl enable --now nginx succeeded"

    nginx_health_probe || fail_step "${EXIT_HEALTH_FAILURE}" nginx_health_check_failed "" "nginx did not answer a health probe on 127.0.0.1:80 after enable --now"
    add_change web.nginx.v1 healthy "" "TCP health probe against 127.0.0.1:80 succeeded"

    checkpoint_write install_nginx "${MANIFEST_DIGEST}"
    log_info "install_nginx complete"
}

# --- phase 4: bootstrap_node_health --------------------------------------
#
# selftest_new_uuid/selftest_envelope/selftest_invoke_agent/
# selftest_status_from_output/run_node_health_selftest_delete now live in
# lib/selftest.sh, shared by every leaf-service installer's own self-test
# (the only nginx-specific thing among them, selftest_envelope's capability
# string, is now its own first parameter, supplied by this installer's own
# run_node_health_selftest below).

# run_node_health_selftest feeds two real OperationEnvelopes, a `create` then
# a `delete`, to the just-placed agent binary, targeting the exact real
# production paths (NGINX_LIVE_DIR, /var/lib/lesta/nginx,
# /etc/nginx/nginx.conf, the real system nginx service install_nginx already
# enabled). The resource is a throwaway domain (selftest.lesta.invalid) that
# exists only for the duration of this function: created, verified applied,
# then deleted, leaving no residue for a control plane that never heard of
# this resource to reconcile.
#
# This deliberately does not start a separate, isolated nginx instance the
# way agent/internal/capability/nginx/harness_test.go's newDisposableNginx
# does for Go unit tests: that technique needs the agent binary to accept
# path overrides, which would widen the shipped production binary's attack
# surface (see productionConfig()'s own comment in
# agent/cmd/lesta-agent/main.go for why that's a real concern, not a
# hypothetical one -- an overridable nginx binary path is a privileged
# arbitrary-exec vector). Testing the exact, unmodified production binary
# against the exact production paths, using the real nginx service
# install_nginx just enabled, is both safer and a stronger proof than a
# synthetic stand-in.
run_node_health_selftest() {
    local resource_id create_idem create_corr delete_idem delete_corr
    local ssl_obj payload envelope agent_out agent_status status_line

    # A fresh UUID every run, not a fixed constant: `delete` records a
    # terminal "deleted" generation for a resource_id rather than forgetting
    # it ever existed (matching real product semantics -- a deleted
    # WebDomain's id is never reused), so a fixed resource_id would make the
    # second installer run's own create see resource_already_exists against
    # the first run's already-deleted history. /proc/sys/kernel/random/uuid
    # is a kernel-provided source with no extra package dependency, and this
    # only ever runs on Linux.
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

    envelope=$(selftest_envelope "${WEB_NGINX_CAPABILITY}" create "${resource_id}" "${create_idem}" "${create_corr}" 1 "${payload}")

    agent_status=0
    agent_out=$(selftest_invoke_agent "${envelope}") || agent_status=$?

    if [ "${agent_status}" -ne 0 ]; then
        add_error selftest_create_failed "agent exited ${agent_status}: $(printf '%s' "${agent_out}" | tr '\n' ' ')" "${AGENT_BINARY_DEST}"
        emit_result_and_exit failed "${EXIT_HEALTH_FAILURE}"
    fi

    status_line=$(selftest_status_from_output "${agent_out}")
    if [ "${status_line}" != "applied" ]; then
        add_error selftest_create_not_applied "agent returned status=${status_line:-unknown} for create, expected applied: $(printf '%s' "${agent_out}" | tr '\n' ' ')" "${AGENT_BINARY_DEST}"
        run_node_health_selftest_delete "${WEB_NGINX_CAPABILITY}" "${resource_id}" "${payload}" "${delete_idem}" "${delete_corr}" || true
        emit_result_and_exit failed "${EXIT_HEALTH_FAILURE}"
    fi

    log_info "bootstrap_node_health self-test: create returned status=applied"

    if ! run_node_health_selftest_delete "${WEB_NGINX_CAPABILITY}" "${resource_id}" "${payload}" "${delete_idem}" "${delete_corr}"; then
        add_error selftest_cleanup_failed "self-test create succeeded but the throwaway resource could not be deleted afterward; ${AGENT_BINARY_DEST} may have left a stray fragment for ${resource_id} under ${NGINX_LIVE_DIR}" "${NGINX_LIVE_DIR}"
        emit_result_and_exit failed "${EXIT_HEALTH_FAILURE}"
    fi

    add_change node.health.v1 installed_structural_only "${AGENT_BINARY_DEST}" "self-test create-then-delete of a throwaway resource (selftest.lesta.invalid) against the real, just-installed nginx returned status=applied both times; remote control-plane registration (mTLS enrollment, heartbeat reporting) is not yet built (no network transport exists between Laravel and the agent this phase), so node.health.v1 is structurally installed and health-checked but NOT YET control-plane-registered"

    log_info "bootstrap_node_health self-test: create+delete both returned status=applied"
}

bootstrap_node_health() {
    log_info "bootstrap_node_health: installing agent binary and running disposable self-test"

    agent_install_binary "${AGENT_BINARY_SRC}" "${NODE_HEALTH_MANIFEST}"

    run_node_health_selftest

    checkpoint_write bootstrap_node_health "${MANIFEST_DIGEST}"
    log_info "bootstrap_node_health complete"
}

# --- phase 5 (apache/both only): delegate to apache/install.sh ------------
#
# exec_apache_installer <web_profile> runs apache/install.sh as a genuine
# child process (`sh ... --apply --yes --web-profile <web_profile>`, never
# sourced: each script keeps its own set -eu/trap/main() fully isolated,
# matching how CI already treats nginx and bind9 as independent sequential
# subprocess invocations against the same box), NOT the shell `exec` builtin:
# this script must still print exactly one combined JSON result of its own
# afterward (INSTALLER-CONTRACT.md's "the only thing this script ever prints
# to stdout"), so apache/install.sh's own separate JSON result is captured
# and summarized into this installer's own changes[]/errors[], never
# forwarded to stdout directly.
#
# "propagating its exit code" (the plan's own wording) means this installer's
# own final exit code equals apache/install.sh's exit code exactly on
# failure: apache/install.sh's own EXIT_* codes come from the identical
# lib/run.sh constants this script itself uses, so re-emitting that same
# integer via emit_result_and_exit is schema-valid without translation.
exec_apache_installer() {
    local web_profile="$1" out status=0

    out=$(sh "${INSTALL_ROOT}/services/apache/install.sh" --apply --yes --web-profile "${web_profile}" 2>&1) || status=$?

    if [ "${status}" -ne 0 ]; then
        add_error apache_delegate_failed "apache/install.sh --apply --yes --web-profile ${web_profile} exited ${status}: $(printf '%s' "${out}" | tr '\n' ' ')" "${INSTALL_ROOT}/services/apache/install.sh"
        emit_result_and_exit failed "${status}"
    fi

    add_change web.apache.v1 delegated "${INSTALL_ROOT}/services/apache/install.sh" "apache/install.sh --apply --yes --web-profile ${web_profile} completed successfully (see its own run log under /var/log/lesta/install/ for full detail); web.apache.v1 is now installed and health-checked on this node"
    log_info "apache/install.sh --web-profile ${web_profile} completed successfully"
}

# --- main -------------------------------------------------------------

main() {
    MANIFEST_DIGEST=$(compute_manifest_digest "${BASE_MANIFEST}" "${FIREWALL_MANIFEST}" "${NODE_HEALTH_MANIFEST}" "${NGINX_MANIFEST}")

    parse_args "$@"
    validate_args

    if [ "${MODE}" = "version" ]; then
        emit_version_and_exit
    fi

    RUN_ID=$(run_generate_id)
    run_install_cleanup_trap

    # No mutation happens before this point: log_init itself would create a
    # directory, and ensure_lesta_group would run groupadd, so both wait
    # until after run_preflight has passed. LOG_PATH is empty here, so
    # log_info below writes to stderr only, matching every other mode.
    log_info "starting install.sh mode=${MODE} web_server=${WEB_SERVER} run_id=${RUN_ID} installer_version=${SCRIPT_VERSION}"

    run_preflight

    if [ "${MODE}" = "dry-run" ]; then
        emit_dry_run_result_and_exit
    fi

    # --apply, and preflight passed: only now is any mutation permitted.
    ensure_lesta_group
    log_init
    log_info "preflight passed; beginning apply mutations"

    case "${WEB_SERVER}" in
        nginx)
            bootstrap_base
            bootstrap_firewall_baseline nginx "${NGINX_MANIFEST}"
            checkpoint_write bootstrap_firewall_baseline "${MANIFEST_DIGEST}"
            install_nginx
            bootstrap_node_health
            ;;
        both)
            bootstrap_base
            bootstrap_firewall_baseline nginx "${NGINX_MANIFEST}"
            checkpoint_write bootstrap_firewall_baseline "${MANIFEST_DIGEST}"
            install_nginx
            bootstrap_node_health
            exec_apache_installer both
            ;;
        apache)
            # nginx itself is never installed for this profile: apache/install.sh
            # is a fully standalone leaf installer (its own bootstrap_base,
            # firewall registration, and node-health self-test), so nothing of
            # this script's own phases runs first.
            exec_apache_installer apache
            ;;
    esac

    checkpoint_remove
    release_write "${RELEASE_ID}" "${MANIFEST_DIGEST}"

    emit_apply_success_and_exit
}

main "$@"
