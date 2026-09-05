#!/bin/sh
# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/services/agent-daemon/install.sh
#
# Bootstrap installer for the agent.daemon.v1 capability: enrolls this node
# with the control plane (exchanging a one-time enrollment token for a
# long-lived node credential) and activates a systemd-supervised daemon that
# heartbeats this node's own liveness and capability presence, and reports
# cron execution history, back to Laravel. Unlike every other installer in
# this family, this one is not tied to any single leaf capability: it is a
# required-once-per-node operational step, run after at least node-health
# (and, in practice, whatever leaf capabilities the operator wants reported)
# is already bootstrapped.
#
# **Not mutual TLS**: this design uses a bearer token over Laravel's own
# already-terminated HTTPS, never a literal client certificate. See
# README.md's own disclosure of this design choice and its operational
# dependency (the control-plane host must actually terminate HTTPS).
#
# Mirrors cron/install.sh's own overall structure closely, sharing the
# capability-agnostic plumbing every installer in this family needs via
# lib/result.sh, lib/agent.sh, and lib/selftest.sh (used here only for
# UUID generation via selftest_new_uuid, never for an OperationEnvelope
# round trip, since agent.daemon.v1 has no provisioning envelope of its
# own). Like cron/install.sh, this installer never sources lib/firewall.sh:
# this service's own manifest.json declares ports: [], since the daemon
# only ever makes outbound connections.
set -eu

# --- constants -------------------------------------------------------------

SCRIPT_VERSION="1.0.0"
RELEASE_ID="2026.09.05"
AGENT_DAEMON_CAPABILITY="agent.daemon.v1"

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
# shellcheck source=../../lib/enrollment.sh
. "${INSTALL_ROOT}/lib/enrollment.sh"
# shellcheck source=../../lib/daemon.sh
. "${INSTALL_ROOT}/lib/daemon.sh"

BASE_MANIFEST="${INSTALL_ROOT}/base/manifest.json"
NODE_HEALTH_MANIFEST="${INSTALL_ROOT}/services/node-health/manifest.json"
AGENT_DAEMON_MANIFEST="${INSTALL_ROOT}/services/agent-daemon/manifest.json"

AGENT_BINARY_SRC="${REPO_ROOT}/agent/dist/lesta-agent-linux-amd64"

# CHECKPOINT_PATH/RELEASE_PATH: this installer's own paths, distinct from
# every other leaf-service installer's own (see lib/checkpoint.sh's own top
# comment).
export CHECKPOINT_PATH="/var/lib/lesta/install/agent-daemon.checkpoint"
export RELEASE_PATH="/etc/lesta/agent-daemon-release"

# --- globals (all pre-declared for `set -u` safety) -------------------------

MODE=""
YES=0
RUN_ID=""
MANIFEST_DIGEST=""
CHANGES=""
ERRORS=""
NODE_UUID=""
ENROLLMENT_TOKEN=""
CONTROL_PLANE_URL=""

# --- usage / argument parsing ----------------------------------------------

usage() {
    cat <<'USAGE' >&2
Usage: install.sh --dry-run|--apply|--version --node-uuid <uuid> --enrollment-token <token> --control-plane-url <url> [--yes] [--help]

  --dry-run             Run preflight and report what would change. No mutation.
  --apply               Apply the installer. Requires --yes.
  --version             Print installer version and exit.
  --node-uuid           The node's own uuid, matching the Node record created on the control plane.
  --enrollment-token    A one-time token issued by `php artisan lesta:nodes:issue-enrollment-token`.
  --control-plane-url   The base URL of the control plane, e.g. https://panel.example.
  --yes                 Required with --apply: non-interactive confirmation.
  --help                Print this message.
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
            --node-uuid)
                [ "$#" -ge 2 ] || fail_invocation "--node-uuid requires a value"
                NODE_UUID="$2"
                shift 2
                ;;
            --enrollment-token)
                [ "$#" -ge 2 ] || fail_invocation "--enrollment-token requires a value"
                ENROLLMENT_TOKEN="$2"
                shift 2
                ;;
            --control-plane-url)
                [ "$#" -ge 2 ] || fail_invocation "--control-plane-url requires a value"
                CONTROL_PLANE_URL="$2"
                shift 2
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

    [ -n "${NODE_UUID}" ] || fail_invocation "--node-uuid is required"
    [ -n "${ENROLLMENT_TOKEN}" ] || fail_invocation "--enrollment-token is required"
    [ -n "${CONTROL_PLANE_URL}" ] || fail_invocation "--control-plane-url is required"

    if [ "${MODE}" = "apply" ] && [ "${YES}" -ne 1 ]; then
        fail_invocation "--apply requires --yes"
    fi
}

# --- result accumulation / emission -----------------------------------------

manifest_capabilities_required_json() {
    local items item lines=""

    items=$(manifest_extract_array "${AGENT_DAEMON_MANIFEST}" "depends_on")

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
    provided_json=$(json_array_from_lines "$(json_str "${AGENT_DAEMON_CAPABILITY}")")

    result=$(json_join_object \
        "$(json_kv_str "schema_version" "1")" \
        "$(json_kv_str "installer" "lesta-bootstrap")" \
        "$(json_kv_str "service" "agent-daemon")" \
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
    add_change node.health.v1 would_install "${AGENT_BINARY_DEST}" "vendored agent binary would be checksum-verified and copied into place (a no-op if node-health already installed it)"
    add_change "${AGENT_DAEMON_CAPABILITY}" would_enroll "/etc/lesta/agent/node-credential" "this node would exchange its enrollment token for a long-lived node credential against ${CONTROL_PLANE_URL}/agent/v1/enroll, unless a credential is already present"
    add_change "${AGENT_DAEMON_CAPABILITY}" would_install "${DAEMON_UNIT_PATH}" "the lesta-agent-daemon systemd unit would be written, enabled, and started, then health-probed via systemctl. No firewall phase runs: this service's own manifest declares no ports"

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
    supported=$(manifest_extract_array "${AGENT_DAEMON_MANIFEST}" "supported_ubuntu")

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
    # declares ports: [], and the daemon never binds a network listener.
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

# --- phase 2: bootstrap_firewall_baseline ------------------------------
#
# Deliberately skipped entirely: this service's own manifest.json declares
# ports: [], since the daemon only ever makes outbound connections. There is
# nothing for a firewall phase to register, so lib/firewall.sh is never even
# sourced by this installer.

# --- phase 3: bootstrap_node_health --------------------------------------
#
# Reuses lib/agent.sh's own agent_install_binary verbatim: this installer
# never runs the OperationEnvelope self-test other leaf installers run
# (agent.daemon.v1 has no provisioning envelope of its own to round-trip
# against), so this phase is only the binary placement, not a full
# bootstrap_node_health equivalent.

bootstrap_node_health() {
    log_info "bootstrap_node_health: installing agent binary"

    agent_install_binary "${AGENT_BINARY_SRC}" "${NODE_HEALTH_MANIFEST}"

    checkpoint_write bootstrap_node_health "${MANIFEST_DIGEST}"
    log_info "bootstrap_node_health complete"
}

# --- phase 4: bootstrap_agent_daemon -------------------------------------

bootstrap_agent_daemon() {
    log_info "bootstrap_agent_daemon: enrolling and activating ${AGENT_DAEMON_CAPABILITY}"

    local response node_credential

    # /var/lib/lesta/agent itself is 0750 root:lesta (created by node-health's
    # own agent_install_binary, via lib/agent.sh): lesta-agent is only ever a
    # group member of that directory, never its owner, so it has read and
    # traverse there but not write, the same class of gap this project has
    # hit repeatedly for other worker identities against a root-owned parent
    # (named/bind, www-data/apache, mysql/mariadb). The daemon process itself
    # runs as lesta-agent (see lib/daemon.sh's own systemd unit) and needs to
    # create and rewrite its own watermark file at runtime, so it needs a
    # subdirectory it actually owns, not a bare file directly inside the
    # root-owned parent.
    install -d -m 0750 -o lesta-agent -g lesta /var/lib/lesta/agent/daemon-state \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta/agent/daemon-state "failed to create /var/lib/lesta/agent/daemon-state"
    add_change "${AGENT_DAEMON_CAPABILITY}" ensured /var/lib/lesta/agent/daemon-state "daemon's own writable runtime-state directory present, mode 0750 lesta-agent:lesta"

    if [ -s /etc/lesta/agent/node-credential ]; then
        add_change "${AGENT_DAEMON_CAPABILITY}" verified /etc/lesta/agent/node-credential "already enrolled by a prior apply"
    else
        response=$(enrollment_post_bootstrap "${CONTROL_PLANE_URL}" "${NODE_UUID}" "${ENROLLMENT_TOKEN}" "${SCRIPT_VERSION}" "1") \
            || fail_step "${EXIT_MUTATION_FAILURE}" enrollment_failed "${CONTROL_PLANE_URL}/agent/v1/enroll" "enrollment POST failed or returned a non-2xx status"

        node_credential=$(enrollment_extract_field "${response}" "node_credential")
        [ -n "${node_credential}" ] || fail_step "${EXIT_MUTATION_FAILURE}" enrollment_response_invalid "" "enrollment response did not contain a node_credential field"

        enrollment_write_credential "${node_credential}"
        add_change "${AGENT_DAEMON_CAPABILITY}" installed /etc/lesta/agent/node-credential "node credential written, mode 0600, the credential itself is never logged"
    fi

    daemon_write_config "${CONTROL_PLANE_URL}" "${NODE_UUID}" 60 "1"
    add_change "${AGENT_DAEMON_CAPABILITY}" ensured "${DAEMON_CONFIG_PATH}" "daemon config written, mode 0640 lesta-agent:lesta"

    daemon_install_systemd_unit
    add_change "${AGENT_DAEMON_CAPABILITY}" installed "${DAEMON_UNIT_PATH}" "systemd unit written"

    daemon_enable_and_start
    add_change "${AGENT_DAEMON_CAPABILITY}" enabled "" "systemctl enable --now lesta-agent-daemon succeeded"

    # Self-test: confirm the daemon process is genuinely active before
    # checkpointing. A real first-heartbeat proof (polling Node.last_seen_at
    # via a real HTTP call back to the control plane) is not attempted here,
    # since this installer has no route to query Laravel's own database
    # state directly; systemctl's own view of the local service is the
    # deepest structural probe available to it.
    sleep 3
    systemctl is-active --quiet lesta-agent-daemon \
        || fail_step "${EXIT_HEALTH_FAILURE}" agent_daemon_not_active "" "lesta-agent-daemon did not report active within 3s of enable --now"
    add_change "${AGENT_DAEMON_CAPABILITY}" healthy "" "systemctl reports lesta-agent-daemon active"

    checkpoint_write bootstrap_agent_daemon "${MANIFEST_DIGEST}"
    log_info "bootstrap_agent_daemon complete"
}

# --- main -------------------------------------------------------------

main() {
    MANIFEST_DIGEST=$(compute_manifest_digest "${BASE_MANIFEST}" "${NODE_HEALTH_MANIFEST}" "${AGENT_DAEMON_MANIFEST}")

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
    # bootstrap_firewall_baseline intentionally skipped: see this file's own
    # "phase 2" comment above.
    bootstrap_node_health
    bootstrap_agent_daemon

    checkpoint_remove
    release_write "${RELEASE_ID}" "${MANIFEST_DIGEST}"

    emit_apply_success_and_exit
}

main "$@"
