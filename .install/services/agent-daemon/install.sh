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

# system.account-identity.v1's own real SFTP rendering targets (Web
# Application Hosting Threat Model and Isolation Design.md step 2b) --
# bootstrapped here rather than under a dedicated leaf-service installer of
# their own, since (like agent-daemon itself) they are required-once-per-
# node infrastructure, not tied to any single leaf capability.
SFTP_SSHD_CONFIG_PATH="/etc/ssh/sshd_config"
SFTP_SSHD_CONFIG_D="/etc/ssh/sshd_config.d"
SFTP_LESTA_CONF_PATH="${SFTP_SSHD_CONFIG_D}/00-lesta.conf"
SFTP_LESTA_LIVE_DIR="${SFTP_SSHD_CONFIG_D}/lesta.d"
SFTP_AUTHORIZED_KEYS_DIR="/etc/lesta/sftp/authorized_keys"
SFTP_ACCOUNTS_ROOT="/var/lib/lesta/web/accounts"

# Real absolute paths on Ubuntu 24.04/26.04, matching every other leaf
# installer's own *_BINARY_PATH sudoers-rule convention.
USERADD_BINARY_PATH="/usr/sbin/useradd"
USERDEL_BINARY_PATH="/usr/sbin/userdel"
SSHD_BINARY_PATH="/usr/sbin/sshd"
SYSTEMCTL_BINARY_PATH="/usr/bin/systemctl"
SUDOERS_LESTA_AGENT_DAEMON_PATH="/etc/sudoers.d/lesta-agent-daemon"

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

# --node-uuid's own sinks (json_kv_str in lib/enrollment.sh and lib/daemon.sh) already
# string-escape backslash/quote/newline, so a malformed value was never a real injection risk --
# this exists purely as an earlier, clearer failure than a confusing enrollment/heartbeat error
# once a copy-paste mistake (an account UUID, a truncated value, stray whitespace) reaches the
# control plane instead. Standard Str::uuid() shape (RFC 4122, lowercase hex, 8-4-4-4-12), the
# only format Node::uuid (App\Concerns\HasUuid) ever generates.
validate_node_uuid() {
    case "${NODE_UUID}" in
        '') return 1 ;;
    esac

    printf '%s' "${NODE_UUID}" | grep -Eq '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'
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

    if ! validate_node_uuid; then
        fail_invocation "--node-uuid is required and must be a valid uuid (got: '${NODE_UUID}')"
    fi
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
    add_change "${AGENT_DAEMON_CAPABILITY}" would_install "${DAEMON_UNIT_PATH}" "the lesta-agent-daemon systemd unit would be written, enabled, and started, then health-probed via systemctl; ${SUDOERS_LESTA_AGENT_DAEMON_PATH} would be rendered and validated, scoping lesta-agent to run ${USERADD_BINARY_PATH}, ${USERDEL_BINARY_PATH}, ${AGENT_BINARY_DEST} identity-ensure-chroot-tree, ${AGENT_BINARY_DEST} identity-write-authorized-keys, ${SSHD_BINARY_PATH} -t, ${SYSTEMCTL_BINARY_PATH} reload ssh, and ${AGENT_BINARY_DEST} files-manager-apply as root, nothing else. No firewall phase runs: this service's own manifest declares no ports"

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

    # Ubuntu 24.04/26.04's own stock sshd_config already ships
    # `Include /etc/ssh/sshd_config.d/*.conf` (confirmed directly against a
    # real image, not assumed) -- unlike nginx/bind9/apache's own main
    # config, this installer never needs to add the line itself, only
    # refuse to proceed if a hardened or customized sshd_config removed it,
    # mirroring those three installers' own "detect, never silently edit an
    # operator-owned file" boundary exactly.
    if ! check_lesta_include_present "${SFTP_SSHD_CONFIG_PATH}" "${SFTP_SSHD_CONFIG_D}/*.conf" "Include"; then
        add_error sshd_config_missing_include \
            "${SFTP_SSHD_CONFIG_PATH} has no \"Include ${SFTP_SSHD_CONFIG_D}/*.conf\" line -- Ubuntu ships this by default; if it was removed, add it back by hand inside ${SFTP_SSHD_CONFIG_PATH} before continuing. This installer never writes to ${SFTP_SSHD_CONFIG_PATH} itself." \
            "${SFTP_SSHD_CONFIG_PATH}"
        failed=1
    fi

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

    install -d -m 0751 -o root -g lesta /etc/lesta || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /etc/lesta "failed to create /etc/lesta"
    install -d -m 0751 -o root -g lesta /var/lib/lesta || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta "failed to create /var/lib/lesta"
    install -d -m 0750 -o root -g lesta /var/log/lesta || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/log/lesta "failed to create /var/log/lesta"
    add_change base.os.v1 ensured /etc/lesta "directory present, mode 0751 root:lesta"
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
    # A no-op on a fresh enrollment (the service doesn't exist yet --
    # bootstrap_agent_daemon below does the real first enable+start with
    # this same binary already in place); on a re-run against an already-
    # enrolled node, this is what actually gets the new binary generation
    # running, since bootstrap_agent_daemon's own `enable --now` does
    # nothing to a service that's already active.
    agent_restart_daemon_if_enabled "${AGENT_DAEMON_CAPABILITY}"

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

    # metrics.usage.v1's own OffsetStateRoot (metricsProductionConfig,
    # cmd/lesta-agent/main.go): the exact same gap as daemon-state just
    # above, found the same way, directly against a real node --
    # statistics/README.md's own claim that "there is nothing distinct
    # left to bootstrap once a web server and the tenant database are
    # already installed" was wrong, since nothing anywhere ever created
    # this directory. Created here, not in nginx/apache/mariadb's own
    # installers, because it is genuinely capability-agnostic (mail/
    # tenant-database/web usage collection all share it) and this
    # installer already runs unconditionally on every node regardless of
    # which capabilities are chosen, exactly like daemon-state above.
    install -d -m 0750 -o lesta-agent -g lesta /var/lib/lesta/statistics/offsets \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta/statistics/offsets "failed to create /var/lib/lesta/statistics/offsets"
    add_change "${AGENT_DAEMON_CAPABILITY}" ensured /var/lib/lesta/statistics/offsets "metrics.usage.v1's own writable offset-bookkeeping directory present, mode 0750 lesta-agent:lesta"

    # system.account-identity.v1's own StateRoot (identityProductionConfig,
    # cmd/lesta-agent/main.go): the exact same gap as daemon-state/
    # statistics/offsets above, found the same way, directly against a
    # real node -- generation.Store's own bookkeeping directory for this
    # capability was never created anywhere, since (like the SFTP
    # prerequisites above) identity has no dedicated leaf-service
    # install.sh of its own to have created it. Created here for the same
    # reason as the two directories just above: this installer already
    # runs unconditionally on every node.
    install -d -m 0750 -o lesta-agent -g lesta /var/lib/lesta/identity \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta/identity "failed to create /var/lib/lesta/identity"
    add_change "${AGENT_DAEMON_CAPABILITY}" ensured /var/lib/lesta/identity "system.account-identity.v1's own generation-history bookkeeping directory present, mode 0750 lesta-agent:lesta"

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
    # deepest structural probe available to it. This is also the closest
    # thing this installer has to the other five installers' own
    # run_node_health_selftest: it is the first real proof the just-placed
    # agent binary (bootstrap_node_health, just above) actually runs, so a
    # failure here gets the same rollback treatment theirs do.
    sleep 3
    systemctl is-active --quiet lesta-agent-daemon \
        || agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" agent_daemon_not_active "" "lesta-agent-daemon did not report active within 3s of enable --now"
    add_change "${AGENT_DAEMON_CAPABILITY}" healthy "" "systemctl reports lesta-agent-daemon active"

    checkpoint_write bootstrap_agent_daemon "${MANIFEST_DIGEST}"
    log_info "bootstrap_agent_daemon complete"
}

# bootstrap_sftp_prerequisite prepares the fixed, node-wide targets
# system.account-identity.v1's own real SFTP rendering (agent/internal/
# capability/identity) needs before its first real Apply call: the
# per-account Match-block directory, the centralized authorized_keys
# directory, the chroot accounts root, and the one static, one-time
# sshd_config.d file that points sshd at all three. Idempotent like every
# other bootstrap_* here: re-running this installer detects the file
# already being in place and only verifies it, never re-writing it
# needlessly.
bootstrap_sftp_prerequisite() {
    log_info "bootstrap_sftp_prerequisite: preparing system.account-identity.v1's own real SFTP rendering targets"

    # 0770 root:lesta, not 0755 root:root: the real lesta-agent-daemon
    # systemd unit runs as the unprivileged lesta-agent user, confirmed
    # directly against a real node -- writeStaging/activateLive/removeLive
    # and writeAuthorizedKeys (activate.go) are plain file writes/renames,
    # never a chown to an arbitrary uid (unlike SFTP_ACCOUNTS_ROOT's own
    # per-account subdirectories just below, which genuinely do need real
    # root and are routed through identity-ensure-chroot-tree instead), so
    # the same "make the parent directory group-writable" fix nginx's/
    # bind9's/cron's own LiveDir/StateRoot already needed this phase
    # applies here too: the real daemon reading Include'd *.conf files as
    # root before dropping privileges never cares about this directory's
    # own group-write bit, only OpenSSH's ChrootDirectory requirement
    # (SFTP_ACCOUNTS_ROOT, unaffected by this) does.
    install -d -m 0770 -o root -g lesta "${SFTP_LESTA_LIVE_DIR}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${SFTP_LESTA_LIVE_DIR}" "failed to create ${SFTP_LESTA_LIVE_DIR}"
    add_change "${AGENT_DAEMON_CAPABILITY}" ensured "${SFTP_LESTA_LIVE_DIR}" "per-account sshd Match-block directory present, mode 0770 root:lesta"

    # 0711 root:root, deliberately NOT root:lesta like SFTP_LESTA_LIVE_DIR
    # just above: sshd reads *.conf Match-block fragments via `Include`
    # during initial config parsing, always as root, so that directory's
    # own group-write bit is harmless. A per-account AuthorizedKeysFile is
    # different in two ways sshd enforces independently, both confirmed
    # directly against a real node via `sshd -d -d -d`: (1) sshd's own
    # monitor process temporarily drops privileges to the *connecting*
    # account's own uid/gid before opening it
    # (auth2-pubkey.c's own temporarily_use_uid/restore_uid pair), so every
    # connecting account, not just root, needs to traverse into this
    # directory -- the world-execute bit grants that, never directory
    # listing (no world-read), so one account's own username stays
    # unenumerable; (2) sshd's own separate secure_path() safety check
    # ("Authentication refused: bad ownership or modes") independently
    # rejects any AuthorizedKeysFile candidate, or any directory component
    # up to it, that has *any* group- or world-write bit, regardless of
    # which group -- so this directory cannot be root:lesta group-writable
    # the way every other owned root in this project is; it must be
    # root:root with no group access at all. That in turn means the real
    # unprivileged lesta-agent-daemon cannot write into this directory
    # directly under any permission scheme, which is why writeAuthorizedKeys
    # is routed through identity-write-authorized-keys (privileged.go) the
    # same way ensureChrootTree already is.
    install -d -m 0711 -o root -g root "${SFTP_AUTHORIZED_KEYS_DIR}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${SFTP_AUTHORIZED_KEYS_DIR}" "failed to create ${SFTP_AUTHORIZED_KEYS_DIR}"
    add_change "${AGENT_DAEMON_CAPABILITY}" ensured "${SFTP_AUTHORIZED_KEYS_DIR}" "centralized authorized_keys directory present, mode 0711 root:root"

    # 0755, not the account subdirectory's own tighter 0750 (see
    # ensureChrootTree's own doc comment in the Go capability): this exact
    # root has no tenant-owned content of its own, only per-account
    # subdirectories the Go capability creates and owns individually on
    # first use.
    install -d -m 0755 -o root -g root "${SFTP_ACCOUNTS_ROOT}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${SFTP_ACCOUNTS_ROOT}" "failed to create ${SFTP_ACCOUNTS_ROOT}"
    add_change "${AGENT_DAEMON_CAPABILITY}" ensured "${SFTP_ACCOUNTS_ROOT}" "chroot accounts root present, mode 0755 root:root"

    # --- sudoers: useradd/userdel/identity-ensure-chroot-tree/sshd/reload --
    #
    # The real lesta-agent-daemon systemd unit runs as the unprivileged
    # lesta-agent user, confirmed directly against a real node, the hard
    # way: a real tenant's own first CronJob create failed with "useradd:
    # Permission denied" -- system.account-identity.v1's own useradd/userdel
    # invocations (agent/internal/capability/identity/exec.go) had never
    # been routed through sudo at all, unlike every other capability's own
    # root-only actions this project already fixed. useradd/userdel always
    # require root (they write /etc/passwd/etc/shadow/etc/group directly);
    # there is no file-permission fix, only running the process itself as
    # root. identity-ensure-chroot-tree grants a third, closely related
    # action, found immediately after fixing the first two on the same real
    # node: ensureChrootTree's own os.MkdirAll under SFTP_ACCOUNTS_ROOT and
    # its two os.Chown calls (one to root:root, one to the tenant's own
    # uid/gid) also require real root, which sudo cannot grant to an
    # in-process Go syscall the way it can a separate exec.Command
    # invocation -- routed instead through this same binary's own
    # "identity-ensure-chroot-tree" CLI mode (privileged.go), the same
    # self-re-exec shape cron's own cron-archive-state already established.
    # sshd -t and systemctl reload ssh grant a fourth and fifth, found
    # immediately after the first three on the same real node:
    # validateCandidate's own `sshd -t` needs root to read the real host
    # key files (0600, root-only) it falls back to when a candidate config
    # names none explicitly; reload's own `systemctl reload ssh` needs root
    # the same way every other systemd-unit mutation this project execs
    # does. systemctl is scoped to exactly "reload ssh" args, never a bare
    # wildcard: unlike every other binary in this rule, a wildcarded
    # systemctl could restart/stop/mask arbitrary units, a real privilege
    # escalation this rule must never grant. Always ensured, even on a
    # re-run past the early-return below: this rule must exist before the
    # very first real account identity is ever created, and re-rendering an
    # already-identical file is a cheap, safe no-op.
    #
    # identity-write-authorized-keys grants a sixth action, found
    # immediately after the first five on the same real node, attempting a
    # real end-to-end SFTP login: sshd's own secure_path() safety check
    # (confirmed directly via `sshd -d -d -d`) independently requires
    # SFTP_AUTHORIZED_KEYS_DIR and every file in it be root-owned with no
    # group/other write bit at all, which the real unprivileged
    # lesta-agent-daemon could never satisfy writing directly -- routed
    # through this same binary's own "identity-write-authorized-keys" CLI
    # mode (privileged.go) the same way identity-ensure-chroot-tree is.
    #
    # files-manager-apply grants a seventh action: files.manager.v1's own
    # create/update/delete verbs must land on disk owned by the tenant's own
    # lesta-t{account_id} uid/gid, identical ownership to the SFTP path, but
    # the unprivileged lesta-agent-daemon cannot chown to an arbitrary
    # tenant uid it doesn't itself run as -- routed through this same
    # binary's own "files-manager-apply" CLI mode (privileged.go) the same
    # way the identity-* actions above are, with the request JSON piped over
    # stdin rather than argv, since it can carry arbitrary file content.
    cat > "${SUDOERS_LESTA_AGENT_DAEMON_PATH}.tmp" <<SUDOERSEOF
lesta-agent ALL=(root) NOPASSWD: ${USERADD_BINARY_PATH} *, ${USERDEL_BINARY_PATH} *, ${AGENT_BINARY_DEST} identity-ensure-chroot-tree *, ${AGENT_BINARY_DEST} identity-write-authorized-keys *, ${SSHD_BINARY_PATH} -t -f *, ${SYSTEMCTL_BINARY_PATH} reload ssh, ${AGENT_BINARY_DEST} files-manager-apply *
SUDOERSEOF
    chmod 0440 "${SUDOERS_LESTA_AGENT_DAEMON_PATH}.tmp"
    chown root:root "${SUDOERS_LESTA_AGENT_DAEMON_PATH}.tmp"

    if ! visudo -c -f "${SUDOERS_LESTA_AGENT_DAEMON_PATH}.tmp" >/dev/null 2>&1; then
        rm -f "${SUDOERS_LESTA_AGENT_DAEMON_PATH}.tmp"
        fail_step "${EXIT_MUTATION_FAILURE}" sudoers_invalid "${SUDOERS_LESTA_AGENT_DAEMON_PATH}" "visudo -c rejected the rendered ${SUDOERS_LESTA_AGENT_DAEMON_PATH}; the candidate file was removed, the real one was never touched"
    fi

    # Same-directory rename: atomic, and sudo's own #includedir
    # /etc/sudoers.d parsing only ever sees the final name.
    mv "${SUDOERS_LESTA_AGENT_DAEMON_PATH}.tmp" "${SUDOERS_LESTA_AGENT_DAEMON_PATH}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${SUDOERS_LESTA_AGENT_DAEMON_PATH}" "failed to activate ${SUDOERS_LESTA_AGENT_DAEMON_PATH}"
    add_change "${AGENT_DAEMON_CAPABILITY}" installed "${SUDOERS_LESTA_AGENT_DAEMON_PATH}" "sudoers rule written and validated: lesta-agent may run ${USERADD_BINARY_PATH}, ${USERDEL_BINARY_PATH}, ${AGENT_BINARY_DEST} identity-ensure-chroot-tree, ${AGENT_BINARY_DEST} identity-write-authorized-keys, ${SSHD_BINARY_PATH} -t, ${SYSTEMCTL_BINARY_PATH} reload ssh, and ${AGENT_BINARY_DEST} files-manager-apply as root, nothing else"

    if [ -f "${SFTP_LESTA_CONF_PATH}" ] && grep -qF "${SFTP_LESTA_LIVE_DIR}" "${SFTP_LESTA_CONF_PATH}"; then
        add_change "${AGENT_DAEMON_CAPABILITY}" verified "${SFTP_LESTA_CONF_PATH}" "already present from a prior apply"
        log_info "bootstrap_sftp_prerequisite complete (already applied)"

        return 0
    fi

    # AuthorizedKeysFile takes multiple whitespace-separated paths and stops
    # at the first that resolves to a real key -- ".ssh/authorized_keys"
    # first keeps every non-LESta account (root, other admins) on its own
    # default lookup exactly as before this file existed. Writing only the
    # second path here was a real bug, found deploying to a real box for the
    # first time: it silently replaced sshd's global default instead of
    # extending it, breaking key auth for every account that isn't a LESta
    # tenant identity -- invisible in CI, whose disposable sshd instance
    # (harness_test.go) sets PermitRootLogin no and never has a competing
    # admin account to break.
    cat > "${SFTP_LESTA_CONF_PATH}.tmp" <<SSHDCONF
AuthorizedKeysFile .ssh/authorized_keys ${SFTP_AUTHORIZED_KEYS_DIR}/%u
Include ${SFTP_LESTA_LIVE_DIR}/*.conf
SSHDCONF
    chmod 0644 "${SFTP_LESTA_CONF_PATH}.tmp"

    # Same-directory rename: atomic, and sshd_config.d's own glob only ever
    # sees the final name.
    mv "${SFTP_LESTA_CONF_PATH}.tmp" "${SFTP_LESTA_CONF_PATH}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${SFTP_LESTA_CONF_PATH}" "failed to activate ${SFTP_LESTA_CONF_PATH}"

    # Validated against the real, live sshd_config (not a scratch copy):
    # unlike the Go capability's own per-account validate.go, which always
    # tests a candidate without disturbing the live config, this file is
    # itself the one-time global prerequisite being activated for real, so
    # there is no "candidate" version of the overall config to test instead.
    if ! sshd -t; then
        rm -f "${SFTP_LESTA_CONF_PATH}"
        fail_step "${EXIT_MUTATION_FAILURE}" sshd_config_invalid "${SFTP_SSHD_CONFIG_PATH}" "sshd -t rejected the real sshd_config after writing ${SFTP_LESTA_CONF_PATH}; the file was removed"
    fi

    add_change "${AGENT_DAEMON_CAPABILITY}" installed "${SFTP_LESTA_CONF_PATH}" "one-time global AuthorizedKeysFile + Include directive written and validated"

    systemctl reload ssh \
        || fail_step "${EXIT_HEALTH_FAILURE}" sshd_reload_failed "" "systemctl reload ssh failed after writing ${SFTP_LESTA_CONF_PATH}"
    add_change "${AGENT_DAEMON_CAPABILITY}" healthy "" "systemctl reload ssh succeeded"

    checkpoint_write bootstrap_sftp_prerequisite "${MANIFEST_DIGEST}"
    log_info "bootstrap_sftp_prerequisite complete"
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
    bootstrap_sftp_prerequisite

    checkpoint_remove
    release_write "${RELEASE_ID}" "${MANIFEST_DIGEST}"

    emit_apply_success_and_exit
}

main "$@"
