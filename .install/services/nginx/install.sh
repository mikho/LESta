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

BASE_MANIFEST="${INSTALL_ROOT}/base/manifest.json"
FIREWALL_MANIFEST="${INSTALL_ROOT}/services/firewall/manifest.json"
NODE_HEALTH_MANIFEST="${INSTALL_ROOT}/services/node-health/manifest.json"
NGINX_MANIFEST="${INSTALL_ROOT}/services/nginx/manifest.json"

AGENT_BINARY_SRC="${REPO_ROOT}/agent/dist/lesta-agent-linux-amd64"
AGENT_BINARY_DEST="/var/lib/lesta/agent/bin/lesta-agent"
AGENT_ARTIFACT_NAME="lesta-agent-linux-amd64"

NFT_TABLE_PATH="/etc/lesta/firewall/lesta.nft"
FIREWALL_UNIT_PATH="/etc/systemd/system/lesta-firewall.service"

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
Usage: install.sh --dry-run|--apply|--version --web-server nginx [--yes] [--help]

  --dry-run                Run preflight and report what would change. No mutation.
  --apply                  Apply the installer. Requires --yes.
  --version                Print installer version and exit.
  --web-server <profile>   Required for --dry-run/--apply. Only "nginx" is
                           implemented this release; "apache" and "both" are
                           rejected.
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
        nginx) ;;
        apache | both)
            fail_invocation "--web-server ${WEB_SERVER} is not implemented; this release only implements nginx"
            ;;
        "")
            fail_invocation "--web-server is required for --dry-run/--apply (only \"nginx\" is implemented this release)"
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

# add_change <capability> <action> <path> <detail>
add_change() {
    local frag
    frag=$(json_join_object \
        "$(json_kv_str "capability" "$1")" \
        "$(json_kv_str "action" "$2")" \
        "$(json_kv_str "path" "$3")" \
        "$(json_kv_str "detail" "$4")")
    CHANGES=$(append_line "${CHANGES}" "${frag}")
}

# add_error <code> <message> [<path>]
add_error() {
    local frag
    frag=$(json_join_object \
        "$(json_kv_str "code" "$1")" \
        "$(json_kv_str "message" "$2")" \
        "$(json_kv_str "path" "${3:-}")")
    ERRORS=$(append_line "${ERRORS}" "${frag}")
}

# fail_step <exit_code> <error_code> <path> <message>
# Records one error and immediately emits the final failed result.
fail_step() {
    add_error "$2" "$4" "$3"
    emit_result_and_exit failed "$1"
}

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
    add_change firewall.baseline.v1 would_apply "${NFT_TABLE_PATH}" "deny-by-default nftables table would be loaded and ${FIREWALL_UNIT_PATH} installed and enabled"
    add_change node.health.v1 would_install "${AGENT_BINARY_DEST}" "vendored agent binary would be checksum-verified and copied into place, then self-tested by creating and deleting a throwaway resource against the real, just-installed nginx"
    add_change web.nginx.v1 would_install "" "apt-get install -y nginx would run; ${NGINX_LIVE_DIR} and /var/lib/lesta/nginx would be created; nginx would be enabled and health-probed"

    emit_result_and_exit would_change "${EXIT_OK}"
}

emit_apply_success_and_exit() {
    log_info "install.sh apply completed successfully"
    emit_result_and_exit applied "${EXIT_OK}"
}

# --- preflight orchestration --------------------------------------------

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

    while IFS= read -r port; do
        [ -n "${port}" ] || continue
        preflight_check_port_free "${port}" || failed=1
    done <<PORTS
$(manifest_extract_ports "${NGINX_MANIFEST}")
PORTS

    preflight_check_conflicting_packages || failed=1
    preflight_check_lesta_identity || failed=1
    preflight_check_include_line || failed=1

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

bootstrap_firewall_baseline() {
    log_info "bootstrap_firewall_baseline: rendering deny-by-default nftables table"

    install -d -m 0750 -o root -g lesta /etc/lesta/firewall || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /etc/lesta/firewall "failed to create /etc/lesta/firewall"
    add_change firewall.baseline.v1 ensured /etc/lesta/firewall "directory present, mode 0750 root:lesta"

    local port port_rules="" out
    while IFS= read -r port; do
        [ -n "${port}" ] || continue
        port_rules=$(append_line "${port_rules}" "${port}")
    done <<PORTS
$(manifest_extract_ports "${NGINX_MANIFEST}")
PORTS
    port_rules=$(printf '%s' "${port_rules}" | tr '\n' ',' | sed -e 's/,/, /g' -e 's/, $//')

    {
        printf 'table inet lesta {\n'
        printf '    chain input {\n'
        printf '        type filter hook input priority 0; policy drop;\n'
        printf '        ct state established,related accept;\n'
        printf '        iif lo accept;\n'
        printf '        icmp type echo-request accept;\n'
        printf '        tcp dport 22 accept;\n'
        printf '        tcp dport { %s } accept;\n' "${port_rules}"
        printf '    }\n'
        printf '}\n'
    } > "${NFT_TABLE_PATH}.tmp"
    chmod 0640 "${NFT_TABLE_PATH}.tmp"
    chown root:lesta "${NFT_TABLE_PATH}.tmp" 2>/dev/null || true

    if ! out=$(nft -c -f "${NFT_TABLE_PATH}.tmp" 2>&1); then
        rm -f "${NFT_TABLE_PATH}.tmp"
        add_error nft_validation_failed "${out}" "${NFT_TABLE_PATH}"
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi

    mv -f "${NFT_TABLE_PATH}.tmp" "${NFT_TABLE_PATH}"

    if ! out=$(nft -f "${NFT_TABLE_PATH}" 2>&1); then
        add_error nft_apply_failed "${out}" "${NFT_TABLE_PATH}"
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi
    add_change firewall.baseline.v1 applied "${NFT_TABLE_PATH}" "deny-by-default inet lesta table loaded (ssh 22, ${port_rules})"

    {
        printf '[Unit]\n'
        printf 'Description=LESta firewall baseline (nftables)\n'
        printf 'After=network-pre.target\n'
        printf 'Before=network.target\n'
        printf '\n'
        printf '[Service]\n'
        printf 'Type=oneshot\n'
        printf 'ExecStart=/usr/sbin/nft -f %s\n' "${NFT_TABLE_PATH}"
        printf 'RemainAfterExit=yes\n'
        printf '\n'
        printf '[Install]\n'
        printf 'WantedBy=multi-user.target\n'
    } > "${FIREWALL_UNIT_PATH}.tmp"
    chmod 0644 "${FIREWALL_UNIT_PATH}.tmp"
    mv -f "${FIREWALL_UNIT_PATH}.tmp" "${FIREWALL_UNIT_PATH}"

    systemctl daemon-reload || fail_step "${EXIT_MUTATION_FAILURE}" systemctl_daemon_reload_failed "${FIREWALL_UNIT_PATH}" "systemctl daemon-reload failed"
    systemctl enable lesta-firewall.service >/dev/null || fail_step "${EXIT_MUTATION_FAILURE}" systemctl_enable_failed "${FIREWALL_UNIT_PATH}" "systemctl enable lesta-firewall.service failed"
    add_change firewall.baseline.v1 enabled "${FIREWALL_UNIT_PATH}" "systemd oneshot unit installed and enabled for boot-time reload"

    checkpoint_write bootstrap_firewall_baseline "${MANIFEST_DIGEST}"
    log_info "bootstrap_firewall_baseline complete"
}

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

    check_lesta_include_present || include_status=$?
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

# selftest_envelope <operation> <resource_id> <idem> <corr> <desired_state_version> <payload>
# -> a complete OperationEnvelope JSON object for the given operation.
selftest_envelope() {
    local operation="$1" resource_id="$2" idem="$3" corr="$4" dsv="$5" payload="$6"
    local issued_at deadline request_digest

    issued_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    deadline=$(date -u -d '+30 seconds' +%Y-%m-%dT%H:%M:%SZ)
    request_digest="sha256:$(printf '%s' "${payload}" | sha256sum | awk '{print $1}')"

    json_join_object \
        "$(json_kv_str "protocol_version" "1")" \
        "$(json_kv_str "capability" "${WEB_NGINX_CAPABILITY}")" \
        "$(json_kv_str "operation" "${operation}")" \
        "$(json_kv_str "resource_id" "${resource_id}")" \
        "$(json_kv_raw "desired_state_version" "${dsv}")" \
        "$(json_kv_str "idempotency_key" "${idem}")" \
        "$(json_kv_str "correlation_id" "${corr}")" \
        "$(json_kv_str "deadline" "${deadline}")" \
        "$(json_kv_str "issued_at" "${issued_at}")" \
        "$(json_kv_str "request_digest" "${request_digest}")" \
        "$(json_kv_raw "payload" "${payload}")"
}

# selftest_invoke_agent <envelope_json> -> the agent's raw stdout on success
# (return 0), or its combined output with a non-zero return on failure. Never
# passes any environment override: the shipped binary only ever knows
# productionConfig()'s fixed paths (see agent/cmd/lesta-agent/main.go).
selftest_invoke_agent() {
    printf '%s' "$1" | "${AGENT_BINARY_DEST}"
}

# selftest_status_from_output <agent_stdout> -> the "status" field's value,
# or empty if not found.
selftest_status_from_output() {
    printf '%s\n' "$1" | sed -n 's/.*"status"[[:space:]]*:[[:space:]]*"\([a-z_]*\)".*/\1/p' | head -n1
}

# run_node_health_selftest_delete <resource_id> <payload> <idem> <corr>
# Issues the matching `delete` for the throwaway resource
# run_node_health_selftest creates. Split out so the "create succeeded, now
# clean up" and "create's own status wasn't applied, still try to clean up"
# paths share one implementation. Logs a warning and returns 1 on any
# failure; never exits the script itself, so callers control what a failed
# cleanup means for the overall result.
run_node_health_selftest_delete() {
    local resource_id="$1" payload="$2" idem="$3" corr="$4"
    local envelope agent_out agent_status status_line

    envelope=$(selftest_envelope delete "${resource_id}" "${idem}" "${corr}" 2 "${payload}")

    agent_status=0
    agent_out=$(selftest_invoke_agent "${envelope}") || agent_status=$?

    if [ "${agent_status}" -ne 0 ]; then
        log_warn "self-test delete: agent exited ${agent_status}: $(printf '%s' "${agent_out}" | tr '\n' ' ')"
        return 1
    fi

    status_line=$(selftest_status_from_output "${agent_out}")
    if [ "${status_line}" != "applied" ]; then
        log_warn "self-test delete: agent returned status=${status_line:-unknown}, expected applied"
        return 1
    fi

    return 0
}

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

    resource_id="4b1f7c9e-6b1a-4a8e-9c2d-2f9b6a7d0001"
    create_idem="4b1f7c9e-6b1a-4a8e-9c2d-2f9b6a7d0002"
    create_corr="4b1f7c9e-6b1a-4a8e-9c2d-2f9b6a7d0003"
    delete_idem="4b1f7c9e-6b1a-4a8e-9c2d-2f9b6a7d0004"
    delete_corr="4b1f7c9e-6b1a-4a8e-9c2d-2f9b6a7d0005"

    ssl_obj=$(json_join_object "$(json_kv_str "mode" "off")")
    payload=$(json_join_object \
        "$(json_kv_str "domain" "selftest.lesta.invalid")" \
        "$(json_kv_raw "aliases" "[]")" \
        "$(json_kv_str "ip_address" "127.0.0.1")" \
        "$(json_kv_str "web_template" "default")" \
        "$(json_kv_raw "ssl" "${ssl_obj}")" \
        "$(json_kv_raw "suspended" "false")")

    envelope=$(selftest_envelope create "${resource_id}" "${create_idem}" "${create_corr}" 1 "${payload}")

    agent_status=0
    agent_out=$(selftest_invoke_agent "${envelope}") || agent_status=$?

    if [ "${agent_status}" -ne 0 ]; then
        add_error selftest_create_failed "agent exited ${agent_status}: $(printf '%s' "${agent_out}" | tr '\n' ' ')" "${AGENT_BINARY_DEST}"
        emit_result_and_exit failed "${EXIT_HEALTH_FAILURE}"
    fi

    status_line=$(selftest_status_from_output "${agent_out}")
    if [ "${status_line}" != "applied" ]; then
        add_error selftest_create_not_applied "agent returned status=${status_line:-unknown} for create, expected applied: $(printf '%s' "${agent_out}" | tr '\n' ' ')" "${AGENT_BINARY_DEST}"
        run_node_health_selftest_delete "${resource_id}" "${payload}" "${delete_idem}" "${delete_corr}" || true
        emit_result_and_exit failed "${EXIT_HEALTH_FAILURE}"
    fi

    log_info "bootstrap_node_health self-test: create returned status=applied"

    if ! run_node_health_selftest_delete "${resource_id}" "${payload}" "${delete_idem}" "${delete_corr}"; then
        add_error selftest_cleanup_failed "self-test create succeeded but the throwaway resource could not be deleted afterward; ${AGENT_BINARY_DEST} may have left a stray fragment for ${resource_id} under ${NGINX_LIVE_DIR}" "${NGINX_LIVE_DIR}"
        emit_result_and_exit failed "${EXIT_HEALTH_FAILURE}"
    fi

    add_change node.health.v1 installed_structural_only "${AGENT_BINARY_DEST}" "self-test create-then-delete of a throwaway resource (selftest.lesta.invalid) against the real, just-installed nginx returned status=applied both times; remote control-plane registration (mTLS enrollment, heartbeat reporting) is not yet built (no network transport exists between Laravel and the agent this phase), so node.health.v1 is structurally installed and health-checked but NOT YET control-plane-registered"

    log_info "bootstrap_node_health self-test: create+delete both returned status=applied"
}

bootstrap_node_health() {
    log_info "bootstrap_node_health: installing agent binary and running disposable self-test"

    install -d -m 0750 -o root -g lesta /etc/lesta/agent || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /etc/lesta/agent "failed to create /etc/lesta/agent"
    install -d -m 0750 -o root -g lesta /var/lib/lesta/agent || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta/agent "failed to create /var/lib/lesta/agent"
    install -d -m 0750 -o root -g lesta /var/log/lesta/agent || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/log/lesta/agent "failed to create /var/log/lesta/agent"
    install -d -m 0750 -o lesta-agent -g lesta /var/lib/lesta/agent/bin || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta/agent/bin "failed to create /var/lib/lesta/agent/bin"
    add_change node.health.v1 ensured /var/lib/lesta/agent "agent directories present with correct ownership"

    local expected_sha256
    expected_sha256=$(manifest_artifact_sha256 "${NODE_HEALTH_MANIFEST}" "${AGENT_ARTIFACT_NAME}")
    if [ -z "${expected_sha256}" ]; then
        fail_step "${EXIT_VERIFICATION_FAILURE}" artifact_not_declared "${NODE_HEALTH_MANIFEST}" "node-health manifest has no ${AGENT_ARTIFACT_NAME} artifacts[] entry"
    fi

    if [ ! -f "${AGENT_BINARY_SRC}" ]; then
        fail_step "${EXIT_VERIFICATION_FAILURE}" artifact_missing "${AGENT_BINARY_SRC}" "vendored agent binary not found; run 'composer agent:build' before packaging a release"
    fi

    verify_sha256 "${AGENT_BINARY_SRC}" "${expected_sha256}" \
        || fail_step "${EXIT_VERIFICATION_FAILURE}" checksum_mismatch "${AGENT_BINARY_SRC}" "vendored agent binary sha256 does not match node-health manifest's artifacts[] entry"

    cp "${AGENT_BINARY_SRC}" "${AGENT_BINARY_DEST}.tmp" || fail_step "${EXIT_MUTATION_FAILURE}" copy_failed "${AGENT_BINARY_DEST}" "failed to copy agent binary into place"
    chmod 0750 "${AGENT_BINARY_DEST}.tmp"
    chown lesta-agent:lesta "${AGENT_BINARY_DEST}.tmp" 2>/dev/null || true
    mv -f "${AGENT_BINARY_DEST}.tmp" "${AGENT_BINARY_DEST}"
    add_change node.health.v1 installed "${AGENT_BINARY_DEST}" "agent binary copied from ${AGENT_BINARY_SRC}, sha256 verified against manifest, mode 0750 lesta-agent:lesta"

    run_node_health_selftest

    checkpoint_write bootstrap_node_health "${MANIFEST_DIGEST}"
    log_info "bootstrap_node_health complete"
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

    bootstrap_base
    bootstrap_firewall_baseline
    install_nginx
    bootstrap_node_health

    checkpoint_remove
    release_write "${RELEASE_ID}" "${MANIFEST_DIGEST}"

    emit_apply_success_and_exit
}

main "$@"
