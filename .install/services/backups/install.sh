#!/bin/sh
# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/services/backups/install.sh
#
# Bootstrap installer for the backup.encrypted-artifacts.v1 capability: takes
# a bare Ubuntu 24.04/26.04 node (or one that already has any other
# leaf-service capability bootstrapped) to a state where the Go agent's own
# backupProductionConfig() preconditions (agent/cmd/lesta-agent/main.go) are
# met and backup.encrypted-artifacts.v1 is structurally installed and
# health-checked, per .install/INSTALLER-CONTRACT.md.
#
# Mirrors cron/install.sh's own overall structure closely (the thinnest
# existing precedent), sharing the capability-agnostic plumbing every
# installer in this family needs via lib/result.sh, lib/agent.sh, and
# lib/selftest.sh. Even thinner than cron's own installer: this service's own
# manifest.json declares packages: [] AND ports: [] -- there is no external
# binary to apt-get install at all (the capability is pure Go: real
# archive/tar, compress/gzip, and crypto/aes+cipher.NewGCM, see
# agent/internal/capability/backup's own package doc comment), no daemon to
# enable/restart, and no AppArmor profile to extend. install_backups below
# shrinks to exactly one thing: ensuring the owned artifacts directory exists
# with the right ownership. lib/firewall.sh is never even sourced, exactly
# like cron/install.sh's own reasoning.
#
# There is no include-line prerequisite either (unlike nginx/bind9/apache):
# this capability never writes into another service's own config tree, it
# only reads other capabilities' own state roots (Config.StateRoots) to
# decide what to archive, and tolerates every one of them being absent --
# a node with only dns.bind9.v1 bootstrapped still gets a real, valid
# (if empty of other capabilities' data) encrypted artifact.
set -eu

# --- constants -------------------------------------------------------------

SCRIPT_VERSION="1.0.0"
RELEASE_ID="2026.09.13"
BACKUP_ENCRYPTED_ARTIFACTS_CAPABILITY="backup.encrypted-artifacts.v1"

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
BACKUPS_MANIFEST="${INSTALL_ROOT}/services/backups/manifest.json"

AGENT_BINARY_SRC="${REPO_ROOT}/agent/dist/lesta-agent-linux-amd64"

# Fixed production path. Mirrors agent/cmd/lesta-agent/main.go's own
# backupProductionConfig() exactly -- these two files are the only two places
# this literal may ever appear; keep them in lockstep.
BACKUPS_ARTIFACTS_ROOT="/var/lib/lesta/backups"

# CHECKPOINT_PATH/RELEASE_PATH: this installer's own paths, distinct from
# every other leaf-service installer's own (see lib/checkpoint.sh's own top
# comment).
export CHECKPOINT_PATH="/var/lib/lesta/install/backups.checkpoint"
export RELEASE_PATH="/etc/lesta/backups-release"

# --- globals (all pre-declared for `set -u` safety) -------------------------

MODE=""
YES=0
RUN_ID=""
MANIFEST_DIGEST=""
CHANGES=""
ERRORS=""

# --- usage / argument parsing ----------------------------------------------
#
# No --offline-bundle here at all: unlike every other leaf-service installer,
# this one never apt-get installs anything, so there is no package to vendor
# offline in the first place.

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

    items=$(manifest_extract_array "${BACKUPS_MANIFEST}" "depends_on")

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
    provided_json=$(json_array_from_lines "$(json_str "${BACKUP_ENCRYPTED_ARTIFACTS_CAPABILITY}")")

    result=$(json_join_object \
        "$(json_kv_str "schema_version" "1")" \
        "$(json_kv_str "installer" "lesta-bootstrap")" \
        "$(json_kv_str "service" "backups")" \
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
    add_change node.health.v1 would_install "${AGENT_BINARY_DEST}" "vendored agent binary would be checksum-verified and copied into place, then self-tested by creating and deleting a throwaway backup artifact against the real, just-installed capability"
    add_change "${BACKUP_ENCRYPTED_ARTIFACTS_CAPABILITY}" would_install "" "${BACKUPS_ARTIFACTS_ROOT} would be created, mode 0750 root:lesta. No package install, no daemon, no firewall phase: this capability is pure Go with no external binary or network listener"

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
    supported=$(manifest_extract_array "${BACKUPS_MANIFEST}" "supported_ubuntu")

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
    # declares ports: [], and this capability opens no network listener. No
    # conflicting-package check either: it never apt-get installs anything.
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
# Deliberately skipped entirely, exactly like cron/install.sh's own
# reasoning: this service's own manifest.json declares ports: [], since this
# capability opens no network listener. lib/firewall.sh is never even
# sourced.

# --- phase 3: install_backups -------------------------------------------
#
# No package to apt-get install, no daemon to enable/restart, no health
# probe against a running service: this capability is pure Go
# (archive/tar + compress/gzip + crypto/aes, see
# agent/internal/capability/backup's own package doc comment), invoked
# on-demand by the daemon dispatch mechanism, never itself a long-running
# process. This phase shrinks to exactly one real action: ensuring the owned
# artifacts directory exists with the right ownership.

install_backups() {
    log_info "install_backups: ensuring the owned artifacts directory exists"

    install -d -m 0750 -o root -g lesta "${BACKUPS_ARTIFACTS_ROOT}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${BACKUPS_ARTIFACTS_ROOT}" "failed to create ${BACKUPS_ARTIFACTS_ROOT}"
    add_change "${BACKUP_ENCRYPTED_ARTIFACTS_CAPABILITY}" ensured "${BACKUPS_ARTIFACTS_ROOT}" "artifacts directory present, mode 0750 root:lesta"

    checkpoint_write install_backups "${MANIFEST_DIGEST}"
    log_info "install_backups complete"
}

# --- phase 4: bootstrap_node_health --------------------------------------
#
# selftest_new_uuid/selftest_envelope/selftest_invoke_agent/
# selftest_status_from_output live in lib/selftest.sh, shared with every
# other leaf-service installer's own self-test. run_node_health_selftest_delete
# is NOT reused here (unlike every other installer): it assumes the same
# payload shape serves both create and delete, but this capability's own two
# verbs have genuinely disjoint payload shapes (create carries
# label/encryption_key, delete carries only artifact_path -- see
# agent/internal/capability/backup/payload.go's own doc comment), so this
# self-test builds its own delete envelope directly instead.

# run_node_health_selftest feeds a real `create` then `delete` OperationEnvelope
# to the just-placed agent binary, targeting the real production
# BACKUPS_ARTIFACTS_ROOT install_backups just created. The create's own
# encryption_key is a throwaway, real 32-byte key (64 hex characters, read
# from the kernel directly), matching CreateBackup.php's own
# bin2hex(random_bytes(32)) shape exactly. artifact_path for the delete is
# reconstructed from the exact same "<ArtifactsRoot>/<resource_id>.tar.enc"
# formula BackupCapability.applyCreate uses (agent/internal/capability/backup/
# capability.go), rather than parsed out of the create response's own Data
# field, keeping this self-test's own JSON handling as simple as every other
# installer's (a bare status-field grep, never a full JSON parse).
run_node_health_selftest() {
    local resource_id create_idem create_corr delete_idem delete_corr
    local encryption_key create_payload delete_payload envelope
    local agent_out agent_status status_line artifact_path

    resource_id=$(selftest_new_uuid)
    create_idem=$(selftest_new_uuid)
    create_corr=$(selftest_new_uuid)
    delete_idem=$(selftest_new_uuid)
    delete_corr=$(selftest_new_uuid)

    encryption_key=$(od -An -tx1 -N32 /dev/urandom | tr -d ' \n')
    artifact_path="${BACKUPS_ARTIFACTS_ROOT}/${resource_id}.tar.enc"

    create_payload=$(json_join_object \
        "$(json_kv_str "label" "lesta-selftest")" \
        "$(json_kv_str "encryption_key" "${encryption_key}")")

    envelope=$(selftest_envelope "${BACKUP_ENCRYPTED_ARTIFACTS_CAPABILITY}" create "${resource_id}" "${create_idem}" "${create_corr}" 1 "${create_payload}")

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

    if [ ! -f "${artifact_path}" ]; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_artifact_missing "${artifact_path}" "create returned status=applied but no artifact was found at ${artifact_path}"
    fi

    log_info "bootstrap_node_health self-test: encrypted artifact ${artifact_path} exists"

    delete_payload=$(json_join_object "$(json_kv_str "artifact_path" "${artifact_path}")")
    envelope=$(selftest_envelope "${BACKUP_ENCRYPTED_ARTIFACTS_CAPABILITY}" delete "${resource_id}" "${delete_idem}" "${delete_corr}" 2 "${delete_payload}")

    agent_status=0
    agent_out=$(selftest_invoke_agent "${envelope}") || agent_status=$?

    if [ "${agent_status}" -ne 0 ]; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_cleanup_failed "${artifact_path}" "self-test create succeeded but delete's own agent invocation exited ${agent_status}: $(printf '%s' "${agent_out}" | tr '\n' ' ')"
    fi

    status_line=$(selftest_status_from_output "${agent_out}")
    if [ "${status_line}" != "applied" ]; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_delete_not_applied "${artifact_path}" "agent returned status=${status_line:-unknown} for delete, expected applied: $(printf '%s' "${agent_out}" | tr '\n' ' ')"
    fi

    if [ -f "${artifact_path}" ]; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_artifact_not_removed "${artifact_path}" "delete returned status=applied but the artifact still exists at ${artifact_path}"
    fi

    add_change "${BACKUP_ENCRYPTED_ARTIFACTS_CAPABILITY}" installed_structural_only "${AGENT_BINARY_DEST}" "self-test create-then-delete of a throwaway encrypted backup artifact against the real, just-installed capability returned status=applied both times; remote control-plane registration is not yet built, so ${BACKUP_ENCRYPTED_ARTIFACTS_CAPABILITY} is structurally installed and health-checked but NOT YET control-plane-registered"
    log_info "bootstrap_node_health self-test: create+delete both returned status=applied, artifact removed after delete"
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
    MANIFEST_DIGEST=$(compute_manifest_digest "${BASE_MANIFEST}" "${NODE_HEALTH_MANIFEST}" "${BACKUPS_MANIFEST}")

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
    install_backups
    bootstrap_node_health

    checkpoint_remove
    release_write "${RELEASE_ID}" "${MANIFEST_DIGEST}"

    emit_apply_success_and_exit
}

main "$@"
