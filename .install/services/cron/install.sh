#!/bin/sh
# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/services/cron/install.sh
#
# Bootstrap installer for the scheduler.account-cron.v1 capability: takes a
# bare Ubuntu 24.04/26.04 node (or one that already has any other
# leaf-service capability bootstrapped) to a state where the Go agent's own
# cronProductionConfig() preconditions (agent/cmd/lesta-agent/main.go) are
# met and scheduler.account-cron.v1 is structurally installed and
# health-checked, per .install/INSTALLER-CONTRACT.md.
#
# Mirrors bind9/install.sh's and mariadb/install.sh's own overall structure
# closely, sharing the capability-agnostic plumbing every installer in this
# family needs via lib/result.sh, lib/agent.sh, and lib/selftest.sh. Unlike
# those two, this installer never sources lib/firewall.sh and never calls
# bootstrap_firewall_baseline at all: this service's own manifest.json
# declares ports: [], since account-scoped cron opens no network listener,
# so there is nothing for a firewall phase to register.
#
# **The key security design (see agent/internal/capability/cron's own
# package doc comment for the full rationale)**: a tenant's raw command text
# never reaches a crontab fragment's own line. Each fragment's scheduled line
# invokes a fixed wrapper, "<AgentBinaryPath> cron-run <resource_id>", where
# resource_id is a server-generated UUID, never tenant input; the wrapper
# reads the real command from a JSON sidecar file at execution time. This
# installer's own job is only to create the fixed, non-root `lesta-cron`
# system user every fragment's line names, install the `cron` package, and
# make sure `/etc/cron.d` and this capability's own state root exist with
# the right ownership -- it never writes a crontab fragment itself.
#
# **Disclosed limitation, not a bug**: every account's cron commands run as
# the SAME shared, non-root `lesta-cron` system user on a given node. There
# is no per-account OS-level isolation this phase (see README.md's own
# "Execution isolation" section) -- the ADR's own alternative-design fork for
# this phase was resolved in favor of the narrowest defensible slice (a
# fixed shared runner identity, never root) rather than building a full
# per-account-user mechanism as an unrequested prerequisite.
set -eu

# --- constants -------------------------------------------------------------

SCRIPT_VERSION="1.0.0"
RELEASE_ID="2026.09.03"
SCHEDULER_CRON_CAPABILITY="scheduler.account-cron.v1"

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
CRON_MANIFEST="${INSTALL_ROOT}/services/cron/manifest.json"

AGENT_BINARY_SRC="${REPO_ROOT}/agent/dist/lesta-agent-linux-amd64"

# Fixed production paths/identity. Mirrors agent/cmd/lesta-agent/main.go's
# own cronProductionConfig() exactly -- these two files are the only two
# places these literals may ever appear; keep them in lockstep.
FRAGMENT_DIR="/etc/cron.d"
CRON_STATE_ROOT="/var/lib/lesta/cron"
RUNNER_USER="lesta-cron"

# CHECKPOINT_PATH/RELEASE_PATH: this installer's own paths, distinct from
# every other leaf-service installer's own (see lib/checkpoint.sh's own top
# comment).
export CHECKPOINT_PATH="/var/lib/lesta/install/cron.checkpoint"
export RELEASE_PATH="/etc/lesta/cron-release"

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

    items=$(manifest_extract_array "${CRON_MANIFEST}" "depends_on")

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
    provided_json=$(json_array_from_lines "$(json_str "${SCHEDULER_CRON_CAPABILITY}")")

    result=$(json_join_object \
        "$(json_kv_str "schema_version" "1")" \
        "$(json_kv_str "installer" "lesta-bootstrap")" \
        "$(json_kv_str "service" "cron")" \
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
    add_change node.health.v1 would_install "${AGENT_BINARY_DEST}" "vendored agent binary would be checksum-verified and copied into place, then self-tested by creating and deleting a throwaway cron job against the real, just-installed cron, including a direct invocation of the cron-run wrapper"
    add_change "${SCHEDULER_CRON_CAPABILITY}" would_install "" "the lesta-cron system user would be created; the cron package would be installed; ${FRAGMENT_DIR} and ${CRON_STATE_ROOT} would be created; cron.service would be enabled and health-probed. No firewall phase runs: this service's own manifest declares no ports"

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
    supported=$(manifest_extract_array "${CRON_MANIFEST}" "supported_ubuntu")

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
    # declares ports: [], and cron never binds a network listener.
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
# ports: [], since account-scoped cron never binds a network listener. There
# is nothing for a firewall phase to register, so lib/firewall.sh is never
# even sourced by this installer.

# --- phase 3: install_cron ----------------------------------------------

# cron_health_probe polls systemctl's own view of cron.service until it
# reports active or timeout elapses. cron has no client protocol to
# round-trip against (unlike mariadb's own real SELECT 1 probe), so a
# systemd status check is the deepest structural probe available here; the
# self-test's own create-then-delete round trip against the real, just-
# installed cron is the deeper proof, matching every other installer's own
# two-layer pattern.
cron_health_probe() {
    local attempt=0

    while [ "${attempt}" -lt 30 ]; do
        if systemctl is-active --quiet cron 2>/dev/null; then
            return 0
        fi

        attempt=$((attempt + 1))
        sleep 1
    done

    return 1
}

install_cron() {
    log_info "install_cron: creating lesta-cron identity, installing cron package, and activating ${SCHEDULER_CRON_CAPABILITY}"

    local out installed_version

    # --- lesta-cron: the fixed, non-root system user every crontab
    # fragment's own line names -------------------------------------------
    if ! id "${RUNNER_USER}" >/dev/null 2>&1; then
        useradd --system --no-create-home --shell /usr/sbin/nologin "${RUNNER_USER}" \
            || fail_step "${EXIT_MUTATION_FAILURE}" useradd_failed /etc/passwd "failed to create system user ${RUNNER_USER}"
        add_change "${SCHEDULER_CRON_CAPABILITY}" created /etc/passwd "created system user ${RUNNER_USER}"
    else
        add_change "${SCHEDULER_CRON_CAPABILITY}" verified /etc/passwd "system user ${RUNNER_USER} already exists"
    fi

    # lesta-cron has no reason to already be a member of the lesta group.
    # The cron-run wrapper it execs on every scheduled tick
    # ("<AgentBinaryPath> cron-run <resource_id>") must itself traverse
    # /var/lib/lesta and /var/lib/lesta/agent (both 0750 root:lesta) to
    # reach /var/lib/lesta/agent/bin/lesta-agent, and must read its own
    # job's JSON sidecar and write its own execution log under
    # ${CRON_STATE_ROOT} -- all of which this function grants group access
    # to below via ownership on ${CRON_STATE_ROOT}'s own subdirectories.
    usermod -aG lesta "${RUNNER_USER}" || fail_step "${EXIT_MUTATION_FAILURE}" usermod_failed "" "usermod -aG lesta ${RUNNER_USER} failed"
    add_change "${SCHEDULER_CRON_CAPABILITY}" group_membership_granted "" "${RUNNER_USER} added to the lesta group, so the cron-run wrapper it execs can traverse /var/lib/lesta and reach the agent binary, its own sidecar, and its own execution log"

    # --- cron package -------------------------------------------------------
    if ! out=$(apt-get install -y cron 2>&1); then
        add_error apt_install_failed "$(printf '%s' "${out}" | tr '\n' ' ')" ""
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi

    installed_version=$(dpkg-query -W -f='${Version}' cron 2>/dev/null || true)
    if [ -z "${installed_version}" ]; then
        fail_step "${EXIT_MUTATION_FAILURE}" apt_install_unverifiable "" "dpkg-query could not report an installed cron version after apt-get install"
    fi
    add_change "${SCHEDULER_CRON_CAPABILITY}" installed "" "apt-get install -y cron succeeded; dpkg-query reports cron version ${installed_version}"

    # --- owned roots ---------------------------------------------------------
    #
    # /etc/cron.d always exists on a stock Ubuntu image; created defensively
    # only, mirroring bind9's/mariadb's own defensive-creation precedent for
    # paths that "always exist" in practice.
    install -d -m 0755 -o root -g root "${FRAGMENT_DIR}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${FRAGMENT_DIR}" "failed to create ${FRAGMENT_DIR}"
    add_change "${SCHEDULER_CRON_CAPABILITY}" ensured "${FRAGMENT_DIR}" "directory present, mode 0755 root:root"

    install -d -m 0750 -o root -g lesta "${CRON_STATE_ROOT}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${CRON_STATE_ROOT}" "failed to create ${CRON_STATE_ROOT}"
    add_change "${SCHEDULER_CRON_CAPABILITY}" ensured "${CRON_STATE_ROOT}" "state root present, mode 0750 root:lesta"

    install -d -m 0750 -o root -g lesta "${CRON_STATE_ROOT}/jobs" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${CRON_STATE_ROOT}/jobs" "failed to create ${CRON_STATE_ROOT}/jobs"
    add_change "${SCHEDULER_CRON_CAPABILITY}" ensured "${CRON_STATE_ROOT}/jobs" "generation-store bookkeeping directory present, mode 0750 root:lesta"

    # setgid (mode 2750): every sidecar JSON file written here (by whichever
    # identity runs the agent's own OperationEnvelope pipeline, today only
    # this installer's own self-test, running as root) inherits group
    # lesta, so lesta-cron (a lesta group member) can read its own job's
    # command at execution time regardless of which identity created it.
    install -d -m 2750 -o root -g lesta "${CRON_STATE_ROOT}/jobs/sidecar" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${CRON_STATE_ROOT}/jobs/sidecar" "failed to create ${CRON_STATE_ROOT}/jobs/sidecar"
    add_change "${SCHEDULER_CRON_CAPABILITY}" ensured "${CRON_STATE_ROOT}/jobs/sidecar" "sidecar directory present, mode 2750 root:lesta (setgid, so lesta-cron can read sidecars it did not create)"

    # setgid + group-write (mode 2770): the cron-run wrapper, running as
    # lesta-cron, creates and appends its own execution-log file here on
    # every scheduled run.
    install -d -m 2770 -o root -g lesta "${CRON_STATE_ROOT}/executions" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${CRON_STATE_ROOT}/executions" "failed to create ${CRON_STATE_ROOT}/executions"
    add_change "${SCHEDULER_CRON_CAPABILITY}" ensured "${CRON_STATE_ROOT}/executions" "execution-log directory present, mode 2770 root:lesta (setgid + group-write, so lesta-cron can create its own log files)"

    # --- enable + health-probe ------------------------------------------
    systemctl enable --now cron || fail_step "${EXIT_HEALTH_FAILURE}" systemctl_enable_failed "" "systemctl enable --now cron failed"
    add_change "${SCHEDULER_CRON_CAPABILITY}" enabled "" "systemctl enable --now cron succeeded"

    cron_health_probe || fail_step "${EXIT_HEALTH_FAILURE}" cron_health_check_failed "" "cron.service did not report active within 30s of enable --now"
    add_change "${SCHEDULER_CRON_CAPABILITY}" healthy "" "systemctl is-active cron reports active"

    checkpoint_write install_cron "${MANIFEST_DIGEST}"
    log_info "install_cron complete"
}

# --- phase 4: bootstrap_node_health --------------------------------------
#
# selftest_new_uuid/selftest_envelope/selftest_invoke_agent/
# selftest_status_from_output/run_node_health_selftest_delete live in
# lib/selftest.sh, shared with every other leaf-service installer's own
# self-test.

# run_node_health_selftest feeds a real `create` then `delete`
# OperationEnvelope to the just-placed agent binary, targeting the exact
# real production paths (FRAGMENT_DIR, CRON_STATE_ROOT, the real cron.service
# install_cron just enabled and health-probed). Beyond the create/delete
# round trip every other installer's own self-test already proves, this one
# additionally invokes the real cron-run wrapper directly against the real
# just-written sidecar -- genuine end-to-end proof that the wrapper mode
# itself (the piece no other installer's own self-test has an equivalent
# of) works against the real, just-installed binary.
run_node_health_selftest() {
    local resource_id create_idem create_corr delete_idem delete_corr payload envelope agent_out agent_status status_line fragment_path wrapper_status

    resource_id=$(selftest_new_uuid)
    create_idem=$(selftest_new_uuid)
    create_corr=$(selftest_new_uuid)
    delete_idem=$(selftest_new_uuid)
    delete_corr=$(selftest_new_uuid)
    fragment_path="${FRAGMENT_DIR}/lesta-${resource_id}"

    payload=$(json_join_object \
        "$(json_kv_str "minute" "*")" \
        "$(json_kv_str "hour" "*")" \
        "$(json_kv_str "day_of_month" "*")" \
        "$(json_kv_str "month" "*")" \
        "$(json_kv_str "day_of_week" "*")" \
        "$(json_kv_str "command" "echo lesta-selftest")" \
        "$(json_kv_raw "suspended" "false")")

    envelope=$(selftest_envelope "${SCHEDULER_CRON_CAPABILITY}" create "${resource_id}" "${create_idem}" "${create_corr}" 1 "${payload}")

    agent_status=0
    agent_out=$(selftest_invoke_agent "${envelope}") || agent_status=$?

    if [ "${agent_status}" -ne 0 ]; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_create_failed "${AGENT_BINARY_DEST}" "agent exited ${agent_status}: $(printf '%s' "${agent_out}" | tr '\n' ' ')"
    fi

    status_line=$(selftest_status_from_output "${agent_out}")
    if [ "${status_line}" != "applied" ]; then
        run_node_health_selftest_delete "${SCHEDULER_CRON_CAPABILITY}" "${resource_id}" "${payload}" "${delete_idem}" "${delete_corr}" || true
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_create_not_applied "${AGENT_BINARY_DEST}" "agent returned status=${status_line:-unknown} for create, expected applied: $(printf '%s' "${agent_out}" | tr '\n' ' ')"
    fi

    log_info "bootstrap_node_health self-test: create returned status=applied"

    if [ ! -f "${fragment_path}" ]; then
        run_node_health_selftest_delete "${SCHEDULER_CRON_CAPABILITY}" "${resource_id}" "${payload}" "${delete_idem}" "${delete_corr}" || true
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_fragment_missing "${fragment_path}" "create returned status=applied but no crontab fragment was found at ${fragment_path}"
    fi

    log_info "bootstrap_node_health self-test: crontab fragment ${fragment_path} exists"

    # Genuine end-to-end proof the wrapper mode itself works: invoke the
    # real, just-installed binary's own "cron-run" CLI mode against the
    # real sidecar this create just wrote, exactly as cron itself would.
    wrapper_status=0
    "${AGENT_BINARY_DEST}" cron-run "${resource_id}" >/dev/null 2>&1 || wrapper_status=$?

    if [ "${wrapper_status}" -ne 0 ]; then
        run_node_health_selftest_delete "${SCHEDULER_CRON_CAPABILITY}" "${resource_id}" "${payload}" "${delete_idem}" "${delete_corr}" || true
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_wrapper_failed "${AGENT_BINARY_DEST}" "${AGENT_BINARY_DEST} cron-run ${resource_id} exited ${wrapper_status}, expected 0"
    fi

    log_info "bootstrap_node_health self-test: cron-run wrapper exited 0 against the real sidecar"

    if ! run_node_health_selftest_delete "${SCHEDULER_CRON_CAPABILITY}" "${resource_id}" "${payload}" "${delete_idem}" "${delete_corr}"; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_cleanup_failed "${FRAGMENT_DIR}" "self-test create succeeded but the throwaway resource could not be deleted afterward"
    fi

    if [ -f "${fragment_path}" ]; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_fragment_not_removed "${fragment_path}" "delete returned status=applied but the crontab fragment still exists at ${fragment_path}"
    fi

    add_change "${SCHEDULER_CRON_CAPABILITY}" installed_structural_only "${AGENT_BINARY_DEST}" "self-test create-then-delete of a throwaway cron job against the real, just-installed cron returned status=applied both times; the cron-run wrapper was also invoked directly against the real sidecar and exited 0; remote control-plane registration is not yet built, so ${SCHEDULER_CRON_CAPABILITY} is structurally installed and health-checked but NOT YET control-plane-registered"
    log_info "bootstrap_node_health self-test: create+delete both returned status=applied, wrapper exited 0, fragment removed after delete"
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
    MANIFEST_DIGEST=$(compute_manifest_digest "${BASE_MANIFEST}" "${NODE_HEALTH_MANIFEST}" "${CRON_MANIFEST}")

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
    install_cron
    bootstrap_node_health

    checkpoint_remove
    release_write "${RELEASE_ID}" "${MANIFEST_DIGEST}"

    emit_apply_success_and_exit
}

main "$@"
