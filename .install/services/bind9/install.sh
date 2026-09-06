#!/bin/sh
# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/services/bind9/install.sh
#
# Bootstrap installer for the second release leaf-service capability: takes
# a bare Ubuntu 24.04/26.04 node (or one that already has web.nginx.v1
# bootstrapped) to a state where the Go agent's bind9ProductionConfig()
# preconditions (agent/cmd/lesta-agent/main.go) are met and dns.bind9.v1 is
# structurally installed and health-checked, per
# .install/INSTALLER-CONTRACT.md.
#
# This mirrors nginx/install.sh's own overall structure closely (same four
# named phases: bootstrap_base, bootstrap_firewall_baseline, install_bind9,
# bootstrap_node_health), sharing the capability-agnostic plumbing both
# installers need via lib/result.sh, lib/firewall.sh, lib/agent.sh, and
# lib/selftest.sh. bootstrap_firewall_baseline in particular is why those
# shared files exist at all: nginx's original installer rendered its entire
# nftables table from only its own manifest's ports every run, which would
# silently close nginx's 80/443 the moment this installer's own firewall
# phase ran on the same node (`nft -f` replaces a table's whole content,
# it does not merge). See lib/firewall.sh's own top comment for the fix.
#
# dns.bind9.v1 is reported as "structurally installed, not yet
# control-plane-registered", for the same reason nginx/install.sh's own
# node.health.v1 change already documents: no network transport exists yet
# between Laravel and the agent, so the self-test this script runs
# (bootstrap_node_health's create-then-delete of a throwaway zone against
# the real, just-installed bind9) is the most that can honestly be proven
# right now.
#
# named.conf's own `include "/etc/bind/lesta.d/*.conf";` line is a
# documented manual operator prerequisite (see README.md's "Manual
# prerequisite" section): this script only ever reads named.conf, never
# writes to it. Critically, that line MUST go in /etc/bind/named.conf
# ITSELF, never in named.conf.local (Ubuntu's own usual convention for
# adding zones): agent/internal/capability/bind9/config.go's Config has no
# field for named.conf.local at all, only NamedConfPath, and
# buildSyntheticConfig in validate.go only ever reads NamedConfPath. An
# include line added to named.conf.local instead would leave the agent
# unable to find its own live directory, breaking every zone operation
# outright.
set -eu

# --- constants -------------------------------------------------------------

SCRIPT_VERSION="1.0.0"
RELEASE_ID="2026.08.29"
DNS_BIND9_CAPABILITY="dns.bind9.v1"

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
# shellcheck source=../../lib/offline-bundle.sh
. "${INSTALL_ROOT}/lib/offline-bundle.sh"
# shellcheck source=../../lib/prepare-config.sh
. "${INSTALL_ROOT}/lib/prepare-config.sh"
# shellcheck source=../../lib/firewall.sh
. "${INSTALL_ROOT}/lib/firewall.sh"
# shellcheck source=../../lib/agent.sh
. "${INSTALL_ROOT}/lib/agent.sh"
# shellcheck source=../../lib/selftest.sh
. "${INSTALL_ROOT}/lib/selftest.sh"

BASE_MANIFEST="${INSTALL_ROOT}/base/manifest.json"
FIREWALL_MANIFEST="${INSTALL_ROOT}/services/firewall/manifest.json"
NODE_HEALTH_MANIFEST="${INSTALL_ROOT}/services/node-health/manifest.json"
BIND9_MANIFEST="${INSTALL_ROOT}/services/bind9/manifest.json"

AGENT_BINARY_SRC="${REPO_ROOT}/agent/dist/lesta-agent-linux-amd64"

# NAMED_CONF_PATH/BIND9_LIVE_DIR: passed explicitly to lib/preflight.sh's
# capability-agnostic check_lesta_include_present, this installer's own
# equivalent to nginx/install.sh's own NGINX_CONF_PATH/NGINX_LIVE_DIR.
NAMED_CONF_PATH="/etc/bind/named.conf"
BIND9_LIVE_DIR="/etc/bind/lesta.d"

# CHECKPOINT_PATH/RELEASE_PATH: this installer's own paths, distinct from
# nginx's own (/var/lib/lesta/install/nginx.checkpoint,
# /etc/lesta/nginx-release), set before calling into lib/checkpoint.sh's
# functions (see that file's own top comment). Exported here (only
# consumed ambiently by lib/checkpoint.sh's functions, a separate sourced
# file, never referenced by name in this file's own body), which keeps a
# single-file static analysis pass from flagging them as unused.
export CHECKPOINT_PATH="/var/lib/lesta/install/bind9.checkpoint"
export RELEASE_PATH="/etc/lesta/bind9-release"

# --- globals (all pre-declared for `set -u` safety) -------------------------

MODE=""
YES=0
OFFLINE_BUNDLE=""
RUN_ID=""
MANIFEST_DIGEST=""
CHANGES=""
ERRORS=""

# --- usage / argument parsing ----------------------------------------------

usage() {
    cat <<'USAGE' >&2
Usage: install.sh --dry-run|--apply|--version|--prepare-config [--yes] [--offline-bundle <path>] [--help]

  --dry-run                Run preflight and report what would change. No mutation.
  --apply                  Apply the installer. Requires --yes.
  --version                Print installer version and exit.
  --prepare-config         Opt-in alternative to the documented manual
                           prerequisite (see README.md's "Manual
                           prerequisite" section): automatically appends
                           'include "/etc/bind/lesta.d/*.conf";' to
                           named.conf itself if it is not already present,
                           then exits -- it never runs preflight or performs
                           any of --dry-run/--apply's own mutations.
                           Requires --yes. Idempotent: a rerun against a
                           conf file that already has the line is a no-op.
                           Never invoked implicitly by --dry-run or --apply;
                           an operator (or CI) must opt into it explicitly.
  --yes                    Required with --apply/--prepare-config: non-interactive confirmation.
  --offline-bundle <path>  Optional. Installs bind9 and bind9-utils from a
                           locally-vendored bundle produced by
                           .install/scripts/build-release.sh instead of the
                           live 'apt-get install -y bind9 bind9-utils' path:
                           every .deb in <path> is sha256-verified against
                           <path>/bundle-manifest.json before any mutation,
                           then installed offline via 'dpkg -i' (no network
                           access required). Absent by default: the live
                           apt-get path is the unconditional default.
  --help                   Print this message.
USAGE
}

# fail_invocation <message> prints the message and usage to stderr, emits a
# failed result JSON on stdout, and exits EXIT_INVALID_INVOCATION. Used for
# every invalid-invocation case (unrecognized flag, missing mode, --apply
# without --yes).
fail_invocation() {
    printf 'install.sh: %s\n' "$1" >&2
    usage
    add_error invalid_invocation "$1" ""
    emit_result_and_exit failed "${EXIT_INVALID_INVOCATION}"
}

parse_args() {
    if [ "$#" -eq 0 ]; then
        fail_invocation "exactly one of --dry-run, --apply, --version, or --prepare-config is required"
    fi

    while [ "$#" -gt 0 ]; do
        case "$1" in
            --dry-run)
                [ -z "${MODE}" ] || fail_invocation "only one of --dry-run/--apply/--version/--prepare-config may be given"
                MODE="dry-run"
                shift
                ;;
            --apply)
                [ -z "${MODE}" ] || fail_invocation "only one of --dry-run/--apply/--version/--prepare-config may be given"
                MODE="apply"
                shift
                ;;
            --version)
                [ -z "${MODE}" ] || fail_invocation "only one of --dry-run/--apply/--version/--prepare-config may be given"
                MODE="version"
                shift
                ;;
            --prepare-config)
                [ -z "${MODE}" ] || fail_invocation "only one of --dry-run/--apply/--version/--prepare-config may be given"
                MODE="prepare-config"
                shift
                ;;
            --yes)
                YES=1
                shift
                ;;
            --offline-bundle)
                [ "$#" -ge 2 ] || fail_invocation "--offline-bundle requires a value"
                OFFLINE_BUNDLE="$2"
                shift 2
                ;;
            --offline-bundle=*)
                OFFLINE_BUNDLE="${1#--offline-bundle=}"
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
        prepare-config)
            if [ "${YES}" -ne 1 ]; then
                fail_invocation "--prepare-config requires --yes"
            fi
            if [ -n "${OFFLINE_BUNDLE}" ]; then
                fail_invocation "--offline-bundle has no effect with --prepare-config: no package is installed in this mode"
            fi
            return 0
            ;;
        dry-run | apply) ;;
        *)
            fail_invocation "exactly one of --dry-run, --apply, --version, or --prepare-config is required"
            ;;
    esac

    if [ "${MODE}" = "apply" ] && [ "${YES}" -ne 1 ]; then
        fail_invocation "--apply requires --yes"
    fi
}

# --- result accumulation / emission -----------------------------------------
#
# add_change/add_error/fail_step live in lib/result.sh.

# manifest_capabilities_required_json -> a JSON array of bind9 manifest's own
# depends_on entries.
manifest_capabilities_required_json() {
    local items item lines=""

    items=$(manifest_extract_array "${BIND9_MANIFEST}" "depends_on")

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
# prints to stdout. Unlike nginx's own result, there is no "web_profile" key
# at all: install-result.schema.json no longer requires it, and dns has no
# equivalent concept.
emit_result_and_exit() {
    local status="$1" exit_code="$2" changes_json errors_json required_json provided_json result

    changes_json=$(json_array_from_lines "${CHANGES}")
    errors_json=$(json_array_from_lines "${ERRORS}")
    required_json=$(manifest_capabilities_required_json)
    provided_json=$(json_array_from_lines "$(json_str "${DNS_BIND9_CAPABILITY}")")

    result=$(json_join_object \
        "$(json_kv_str "schema_version" "1")" \
        "$(json_kv_str "installer" "lesta-bootstrap")" \
        "$(json_kv_str "service" "dns")" \
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

# bind9_would_install_note prints the dry-run "how bind9 would actually get
# installed" sentence, offline-bundle-aware: the live apt-get path is the
# unconditional default, mirroring nginx/install.sh's own
# nginx_would_install_note exactly.
bind9_would_install_note() {
    if [ -n "${OFFLINE_BUNDLE}" ]; then
        printf 'every vendored .deb in %s would be sha256-verified against %s/%s, then installed offline via dpkg -i (no network access required)%s' \
            "${OFFLINE_BUNDLE}" "${OFFLINE_BUNDLE}" "${BUNDLE_MANIFEST_FILENAME}" "$(offline_bundle_would_retain_note bind9 "${OFFLINE_BUNDLE}" bind9)"
    else
        printf 'apt-get install -y bind9 bind9-utils would run'
    fi
}

emit_dry_run_result_and_exit() {
    local install_state
    install_state=$(preflight_classify_install_state)

    add_change base.os.v1 would_ensure /etc/lesta "base directories and lesta/lesta-agent identity would be created or verified; install-state classification: ${install_state}"
    add_change firewall.baseline.v1 would_apply "${NFT_TABLE_PATH}" "deny-by-default nftables table would be loaded and ${FIREWALL_UNIT_PATH} installed and enabled, unioned with any other service already registered on this node"
    add_change node.health.v1 would_install "${AGENT_BINARY_DEST}" "vendored agent binary would be checksum-verified and copied into place, then self-tested by creating and deleting a throwaway zone against the real, just-installed bind9"
    add_change dns.bind9.v1 would_install "${OFFLINE_BUNDLE}" "$(bind9_would_install_note); ${BIND9_LIVE_DIR} and /var/lib/lesta/bind would be created; bind added to the lesta group; named's AppArmor profile would be extended to allow reading /var/lib/lesta/bind; bind9 would be enabled, restarted, and health-probed"

    emit_result_and_exit would_change "${EXIT_OK}"
}

emit_apply_success_and_exit() {
    log_info "install.sh apply completed successfully"
    emit_result_and_exit applied "${EXIT_OK}"
}

# --- preflight orchestration --------------------------------------------

# preflight_check_include_line wraps the shared check_lesta_include_present
# with the add_error call and remediation text specific to named.conf. The
# remediation text is deliberately explicit that the line goes in
# named.conf itself, never named.conf.local: see this file's own top
# comment for why (agent/internal/capability/bind9/config.go's Config has
# no field for named.conf.local at all).
preflight_check_include_line() {
    local status=0

    check_lesta_include_present "${NAMED_CONF_PATH}" "${BIND9_LIVE_DIR}/*.conf" "include" || status=$?

    case "${status}" in
        0)
            return 0
            ;;
        1)
            add_error bind9_named_conf_missing "named.conf not found at ${NAMED_CONF_PATH}; install bind9 first (apt-get install -y bind9 bind9-utils), then add this line inside named.conf itself (NOT named.conf.local -- the agent only ever reads named.conf): include \"${BIND9_LIVE_DIR}/*.conf\"; -- do not remove any other existing include lines. This installer never writes to named.conf itself." "${NAMED_CONF_PATH}"
            return 1
            ;;
        *)
            add_error bind9_named_conf_missing_include "${NAMED_CONF_PATH} exists but has no include \"${BIND9_LIVE_DIR}/*.conf\"; line. Add that exact line by hand, inside named.conf itself (NOT named.conf.local -- despite that being Ubuntu's own usual convention for adding zones, the agent's Config only ever reads named.conf, never named.conf.local) -- do not remove any other existing include lines. This installer never writes to named.conf itself." "${NAMED_CONF_PATH}"
            return 1
            ;;
    esac
}

# run_prepare_config is the --prepare-config mode's own entry point: calls
# the shared insert_lesta_include_if_missing (lib/prepare-config.sh) with
# this installer's own named.conf path/glob/keyword/line/mode (the exact
# same values preflight_check_include_line above already uses; "append"
# mode, since named.conf has no http{}-style block to insert inside -- a
# bare end-of-file line is a syntactically valid place for its own include
# statement), then emits its own result and exits -- it never runs
# run_preflight or performs any of --dry-run/--apply's own mutations.
run_prepare_config() {
    local status=0

    insert_lesta_include_if_missing "${NAMED_CONF_PATH}" "${BIND9_LIVE_DIR}/*.conf" "include" "include \"${BIND9_LIVE_DIR}/*.conf\";" append || status=$?

    case "${status}" in
        0)
            add_change dns.bind9.v1 config_prepared "${NAMED_CONF_PATH}" "include \"${BIND9_LIVE_DIR}/*.conf\"; is now present in named.conf itself (either it was already there, a no-op, or it was just appended)"
            emit_result_and_exit applied "${EXIT_OK}"
            ;;
        1)
            add_error bind9_named_conf_missing "named.conf not found at ${NAMED_CONF_PATH}; install bind9 first (apt-get install -y bind9 bind9-utils), then rerun --prepare-config or add the include line by hand." "${NAMED_CONF_PATH}"
            emit_result_and_exit failed "${EXIT_PREFLIGHT_CONFLICT}"
            ;;
        *)
            add_error bind9_named_conf_prepare_failed "failed to append the include line to ${NAMED_CONF_PATH}; investigate manually before retrying." "${NAMED_CONF_PATH}"
            emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
            ;;
    esac
}

# preflight_check_conflicting_dns_packages
# No installed-package displacement check for "another DNS server" existed
# before this installer; added here, minimal, for the same reasoning
# nginx/install.sh's own preflight_check_conflicting_packages already
# documents for apache2/lighttpd: this installer refuses to displace an
# existing, operator-managed alternative DNS resolver package.
preflight_check_conflicting_dns_packages() {
    if dpkg -l 2>/dev/null | grep -E '^ii[[:space:]]+(dnsmasq|unbound|powerdns)\b' >/dev/null 2>&1; then
        add_error conflicting_package "dnsmasq, unbound, or powerdns is already installed (dpkg -l); this installer refuses to displace an existing DNS resolver package" ""
        return 1
    fi

    return 0
}

run_preflight() {
    local os_id os_version_id arch supported dir protocol port failed=0

    if [ "$(id -u)" -ne 0 ]; then
        add_error not_root "install.sh must run as root (uid 0)" ""
        emit_result_and_exit failed "${EXIT_UNSUPPORTED_PLATFORM}"
    fi

    os_id=$(preflight_os_release_field ID)
    os_version_id=$(preflight_os_release_field VERSION_ID)
    supported=$(manifest_extract_array "${BIND9_MANIFEST}" "supported_ubuntu")

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

    # bind9's manifest declares both a tcp and a udp entry for port 53;
    # manifest_extract_port_specs (lib/firewall.sh) reports "<protocol>
    # <port>" pairs, so both are checked against their own protocol's `ss`
    # listing rather than assuming tcp.
    while IFS=' ' read -r protocol port; do
        if [ -z "${protocol}" ] || [ -z "${port}" ]; then
            continue
        fi
        preflight_check_port_free "${port}" "${protocol}" named || failed=1
    done <<PORTS
$(manifest_extract_port_specs "${BIND9_MANIFEST}")
PORTS

    preflight_check_conflicting_dns_packages || failed=1
    preflight_check_lesta_identity || failed=1
    preflight_check_include_line || failed=1

    if [ -n "${OFFLINE_BUNDLE}" ]; then
        preflight_check_offline_bundle_present "${OFFLINE_BUNDLE}" || failed=1
    fi

    if [ "${failed}" -ne 0 ]; then
        emit_result_and_exit failed "${EXIT_PREFLIGHT_CONFLICT}"
    fi

    log_info "preflight passed"
}

# --- phase 1: bootstrap_base -------------------------------------------
#
# Identical to nginx/install.sh's own bootstrap_base (capability-agnostic:
# creates the lesta group, lesta-agent user, and the three base
# directories, then refreshes CA certs). Duplicated rather than shared via
# a new lib file for this phase, matching the concrete "New shared lib
# files" scope this phase's plan actually enumerated (lib/result.sh,
# lib/firewall.sh, lib/agent.sh, lib/selftest.sh only).

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

# --- phase 2: bootstrap_firewall_baseline -------------------------------
#
# Shared (lib/firewall.sh): registers bind9's own ports (udp 53, tcp 53)
# into its own fragment, then renders the union of every service's
# registered ports. See lib/firewall.sh's own top comment.

# --- phase 3: install_bind9 ---------------------------------------------

# bind9_package_provenance_note <installed_version> -> a short "what
# actually happened" note, mirroring nginx/install.sh's own
# nginx_package_provenance_note exactly (same cached-.deb-sha256-or-
# version-string reasoning, just against bind9's own package name).
bind9_package_provenance_note() {
    local version="$1" deb_path deb_sha

    deb_path=$(find /var/cache/apt/archives -maxdepth 1 -name "bind9_${version}_*.deb" -print -quit 2>/dev/null || true)

    if [ -n "${deb_path}" ] && [ -f "${deb_path}" ]; then
        deb_sha=$(compute_sha256 "${deb_path}")
        printf 'cached package %s sha256=%s' "$(basename "${deb_path}")" "${deb_sha}"
    else
        printf 'apt cache already cleared; recording version string only (%s)' "${version}"
    fi
}

# bind9_health_probe -> a plain TCP-connect probe against 127.0.0.1:53,
# preferring nc, falling back to a bash /dev/tcp connect, falling back to
# curl as a last resort. DNS is usually UDP-first, but a TCP listener check
# is still meaningful here as the installer's own shallow structural probe
# -- the self-test's real create/delete round-trip against the agent is the
# deep proof, matching nginx's own two-layer pattern.
#
# Unlike nginx/install.sh's own nginx_health_probe (which leads with curl,
# appropriate there since curl speaks real HTTP), this leads with nc/bash
# instead: curl's telnet:// scheme is not a bare TCP-connect check, it
# attempts actual RFC854 telnet option negotiation, which named does not
# speak, making it unsuitable for probing a non-HTTP protocol port; nc -z
# and bash's /dev/tcp are both purpose-built zero-I/O connect tests. curl
# is kept only as a last-resort fallback for a system with neither nc nor
# bash present, which every Linux system this installer targets has.
bind9_health_probe() {
    if command -v nc >/dev/null 2>&1; then
        nc -z -w 5 127.0.0.1 53
        return $?
    fi

    if command -v bash >/dev/null 2>&1; then
        bash -c 'exec 3<>/dev/tcp/127.0.0.1/53' 2>/dev/null
        return $?
    fi

    if command -v curl >/dev/null 2>&1; then
        curl -fsS --max-time 5 -o /dev/null telnet://127.0.0.1:53 2>/dev/null
        return $?
    fi

    return 1
}

# bind9_fail_health <exit_code> <error_code> <path> <message>
# Wraps every package-level health-check failure site below
# (named-checkconf / systemctl enable named / systemctl restart named /
# bind9_health_probe): on the live apt-get path (OFFLINE_BUNDLE empty) this
# is byte-for-byte the same plain fail_step call these sites always made;
# only the --offline-bundle path additionally attempts an automatic
# rollback to the previous retained generation first. named.service is the
# real unit (bind9.service is only a systemd alias, see this file's own
# comment at its systemctl enable named call below), so that is the unit
# restarted on rollback.
bind9_fail_health() {
    if [ -n "${OFFLINE_BUNDLE}" ]; then
        offline_bundle_fail_health_with_rollback "$1" "$2" "$3" "$4" bind9 named
    else
        fail_step "$1" "$2" "$3" "$4"
    fi
}

# install_bind9_offline_bundle <bundle_dir>
# The --offline-bundle counterpart to the live 'apt-get install -y bind9
# bind9-utils' branch below: verifies every vendored .deb first (fail
# closed, no mutation before this returns), then installs via 'dpkg -i',
# requiring no network access at all. Mirrors
# nginx/install.sh's own install_nginx_offline_bundle exactly (see
# lib/offline-bundle.sh for the shared verification logic), including the
# retain-then-verify-then-install-then-snapshot ordering.
install_bind9_offline_bundle() {
    local bundle_dir="$1" out

    log_info "install_bind9: installing bind9 from offline bundle ${bundle_dir} (no network access required)"

    offline_bundle_retain_generation bind9 "${bundle_dir}" bind9

    verify_offline_bundle_artifacts "${bundle_dir}"
    add_change dns.bind9.v1 verified "${bundle_dir}" "every vendored .deb in the offline bundle matched its ${BUNDLE_MANIFEST_FILENAME} sha256; proceeding to offline install"

    if ! out=$(install_offline_bundle_debs "${bundle_dir}"); then
        add_error dpkg_install_failed "$(printf '%s' "${out}" | tr '\n' ' ')" "${bundle_dir}"
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi

    offline_bundle_snapshot_current bind9 "${bundle_dir}"
    add_change dns.bind9.v1 generation_retained "/var/lib/lesta/bind9/bundle-generations" "offline-bundle generation bookkeeping updated: current/ now reflects this bundle; previous/ holds the prior generation if the bind9 version actually changed"
}

install_bind9() {
    log_info "install_bind9: installing bind9 package and activating dns.bind9.v1"

    local out installed_version deb_note include_status=0 apparmor_local_named

    # named's own `include "<dir>/*.conf";` glob-include fails outright
    # ("file not found") if the glob matches zero files (verified directly
    # against a local BIND9 install; unlike nginx's equivalent include,
    # which tolerates an empty match). preflight_check_include_line above
    # already guarantees named.conf itself contains this include line
    # before install_bind9 ever runs, so BIND9_LIVE_DIR (with a placeholder
    # fragment if empty) MUST exist before the package-install step below,
    # not after it: bind9's own package postinst script re-validates
    # named.conf's syntax during a real reinstall (dpkg -i, always
    # unconditional, unlike apt-get install against an already-installed
    # package, which is a no-op and never re-triggers postinst) --
    # confirmed for real via a CI failure where --offline-bundle's own
    # dpkg -i failed with "named.conf:12: parsing failed: file not found"
    # because this directory did not yet exist at that point. Same
    # filename/content as agent/internal/capability/bind9/validate.go's own
    # ensurePlaceholderFragment, so its own idempotent glob check sees this
    # file as already-present and does nothing later.
    install -d -m 0755 "${BIND9_LIVE_DIR}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${BIND9_LIVE_DIR}" "failed to create ${BIND9_LIVE_DIR}"
    add_change dns.bind9.v1 ensured "${BIND9_LIVE_DIR}" "include directory present, mode 0755"

    if [ -z "$(find "${BIND9_LIVE_DIR}" -maxdepth 1 -name '*.conf' -print -quit 2>/dev/null)" ]; then
        cat > "${BIND9_LIVE_DIR}/_lesta-placeholder.conf" <<'PLACEHOLDER'
# Managed by LESta. Do not remove: keeps named's glob include
# valid when no zones have been created yet.
PLACEHOLDER
        chmod 0644 "${BIND9_LIVE_DIR}/_lesta-placeholder.conf"
        add_change dns.bind9.v1 seeded "${BIND9_LIVE_DIR}/_lesta-placeholder.conf" "placeholder fragment written so named's glob include has at least one match before any real zone exists"
    fi

    if [ -n "${OFFLINE_BUNDLE}" ]; then
        install_bind9_offline_bundle "${OFFLINE_BUNDLE}"

        installed_version=$(dpkg-query -W -f='${Version}' bind9 2>/dev/null || true)
        if [ -z "${installed_version}" ]; then
            fail_step "${EXIT_MUTATION_FAILURE}" apt_install_unverifiable "" "dpkg-query could not report an installed bind9 version after dpkg -i from the offline bundle"
        fi
        add_change dns.bind9.v1 installed "${OFFLINE_BUNDLE}" "dpkg -i ${OFFLINE_BUNDLE}/*.deb succeeded (fully offline, no network access used); dpkg-query reports version ${installed_version}"
    else
        if ! out=$(apt-get install -y bind9 bind9-utils 2>&1); then
            add_error apt_install_failed "$(printf '%s' "${out}" | tr '\n' ' ')" ""
            emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
        fi

        installed_version=$(dpkg-query -W -f='${Version}' bind9 2>/dev/null || true)
        if [ -z "${installed_version}" ]; then
            fail_step "${EXIT_MUTATION_FAILURE}" apt_install_unverifiable "" "dpkg-query could not report an installed bind9 version after apt-get install"
        fi

        deb_note=$(bind9_package_provenance_note "${installed_version}")
        add_change dns.bind9.v1 installed "" "apt-get install -y bind9 bind9-utils succeeded; dpkg-query reports bind9 version ${installed_version}. ${deb_note}"
    fi

    # named runs as the bind9 package's own system user (bind on Ubuntu, see
    # named.service's own `-u bind`), which apt-get install just created --
    # it has no reason to already be a member of the lesta group. Unlike
    # nginx's worker (which never needs to read anything under its own
    # state root directly: its served content lives entirely inside the
    # world-traversable NGINX_LIVE_DIR tree), bind9's zone stanza's own
    # `file` directive points straight at a path under /var/lib/lesta/bind
    # (0750 root:lesta), which named itself must open at zone-load time,
    # not just the agent. Without this, named's own Unix DAC check would
    # fail traversing the parent directory regardless of anything else.
    # (Verified directly against CI that this grant alone is NOT
    # sufficient by itself: AppArmor confinement, fixed separately below,
    # was the actual blocker in every observed failure. Both gaps are
    # real and independent -- this one is defense in depth, matching
    # lesta-agent's own existing group membership.)
    usermod -aG lesta bind || fail_step "${EXIT_MUTATION_FAILURE}" usermod_failed "" "usermod -aG lesta bind failed"
    add_change dns.bind9.v1 group_membership_granted "" "bind added to the lesta group, so named can traverse /var/lib/lesta/bind to read zone data"

    # Ubuntu's bind9 package ships an AppArmor profile for /usr/sbin/named
    # (/etc/apparmor.d/usr.sbin.named) that allow-lists only /etc/bind/**,
    # /var/lib/bind/**, and /var/cache/bind/** for zone/config data -- it
    # has no rule at all for /var/lib/lesta/bind, so named is denied
    # outright under AppArmor's default-deny model, independent of (and in
    # addition to) the Unix group grant above. Confirmed directly against
    # CI: named kept logging a plain "permission denied" loading the zone
    # file even after the group grant and a forced restart; reading the
    # actual shipped profile confirmed the missing rule, and cat'ing
    # /etc/apparmor.d/local/usr.sbin.named confirmed the profile's own
    # last line (`#include <local/usr.sbin.named>`) is Ubuntu's own
    # sanctioned extension point for exactly this, specifically so
    # packages/operators never need to edit the vendor-shipped profile
    # file itself (which would be overwritten on the next bind9 package
    # upgrade). named only ever reads zone data here (zones are type
    # primary with content re-rendered by the agent on every generation,
    # never dynamic-update zones), so read-only is sufficient.
    apparmor_local_named="/etc/apparmor.d/local/usr.sbin.named"
    if [ -f "${apparmor_local_named}" ]; then
        if ! grep -qF "/var/lib/lesta/bind/" "${apparmor_local_named}" 2>/dev/null; then
            {
                printf '\n# Added by LESta: named must read its own zone data here.\n'
                printf '/var/lib/lesta/bind/ r,\n'
                printf '/var/lib/lesta/bind/** r,\n'
            } >> "${apparmor_local_named}" || fail_step "${EXIT_MUTATION_FAILURE}" apparmor_override_write_failed "${apparmor_local_named}" "failed to append the /var/lib/lesta/bind allowance to ${apparmor_local_named}"
            add_change dns.bind9.v1 apparmor_extended "${apparmor_local_named}" "granted named read access to /var/lib/lesta/bind via Ubuntu's own local-override include point"
        fi

        if command -v apparmor_parser >/dev/null 2>&1; then
            apparmor_parser -r /etc/apparmor.d/usr.sbin.named || fail_step "${EXIT_MUTATION_FAILURE}" apparmor_reload_failed "${apparmor_local_named}" "apparmor_parser -r /etc/apparmor.d/usr.sbin.named failed"
            add_change dns.bind9.v1 apparmor_reloaded "" "apparmor_parser -r reloaded the named profile with the /var/lib/lesta/bind allowance"
        fi
    else
        log_info "AppArmor local override file for named not present (${apparmor_local_named}); assuming AppArmor is not enforcing named on this host, skipping"
    fi

    install -d -m 0750 -o root -g lesta /var/lib/lesta/bind || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta/bind "failed to create /var/lib/lesta/bind"
    add_change dns.bind9.v1 ensured /var/lib/lesta/bind "state directory present, mode 0750 root:lesta"

    check_lesta_include_present "${NAMED_CONF_PATH}" "${BIND9_LIVE_DIR}/*.conf" "include" || include_status=$?
    if [ "${include_status}" -ne 0 ]; then
        fail_step "${EXIT_PREFLIGHT_CONFLICT}" bind9_named_conf_include_missing "${NAMED_CONF_PATH}" "the lesta.d include line disappeared between preflight and this defensive re-check; investigate concurrent named.conf edits"
    fi

    if ! out=$(named-checkconf "${NAMED_CONF_PATH}" 2>&1); then
        bind9_fail_health "${EXIT_HEALTH_FAILURE}" bind9_checkconf_failed "${NAMED_CONF_PATH}" "$(printf '%s' "${out}" | tr '\n' ' ')"
    fi
    add_change dns.bind9.v1 validated "" "named-checkconf ${NAMED_CONF_PATH} passed"

    # The Ubuntu bind9 package's real unit is named.service; bind9.service is
    # only a systemd alias for it (Alias= in named.service's own [Install]
    # section). `systemctl start`/`stop` resolve an alias transparently, but
    # `systemctl enable` (and `enable --now`) refuses to operate on an alias
    # name outright ("Refusing to operate on alias name or linked unit
    # file"), so the real unit name must be used here.
    systemctl enable named || bind9_fail_health "${EXIT_HEALTH_FAILURE}" systemctl_enable_failed "" "systemctl enable named failed"

    # A restart, not `enable --now` or a bare `rndc reload`: the bind9
    # package's own postinst starts named automatically during `apt-get
    # install` above, before the `usermod -aG lesta bind` call earlier in
    # this function ever ran. Unix supplementary groups are fixed at
    # process-exec time (via initgroups, when named drops from root to the
    # bind user) and are never re-read from /etc/group while a process is
    # already running, and `rndc reload` only re-reads config/zones inside
    # the SAME running process, it does not re-exec the daemon. Verified
    # directly against CI: with only `enable --now` + `rndc reload`, named
    # kept failing every zone load with "permission denied" on
    # /var/lib/lesta/bind even though `id bind` correctly showed the lesta
    # group and namei confirmed every path component's Unix permissions
    # were otherwise fine -- because the already-running, pre-group-grant
    # process was never replaced. An unconditional restart guarantees a
    # fresh exec that picks up the just-granted group, whether named was
    # already running from the postinst or not started yet.
    if ! out=$(systemctl restart named 2>&1); then
        bind9_fail_health "${EXIT_HEALTH_FAILURE}" bind9_restart_failed "" "$(printf '%s' "${out}" | tr '\n' ' ')"
    fi
    add_change dns.bind9.v1 enabled "" "systemctl enable named + systemctl restart named succeeded"

    bind9_health_probe || bind9_fail_health "${EXIT_HEALTH_FAILURE}" bind9_health_check_failed "" "bind9 did not answer a TCP health probe on 127.0.0.1:53 after enable --now"
    add_change dns.bind9.v1 healthy "" "TCP health probe against 127.0.0.1:53 succeeded"

    checkpoint_write install_bind9 "${MANIFEST_DIGEST}"
    log_info "install_bind9 complete"
}

# --- phase 4: bootstrap_node_health --------------------------------------
#
# selftest_new_uuid/selftest_envelope/selftest_invoke_agent/
# selftest_status_from_output/run_node_health_selftest_delete live in
# lib/selftest.sh, shared with nginx/install.sh's own self-test.

# run_node_health_selftest feeds two real OperationEnvelopes, a `create`
# then a `delete`, to the just-placed agent binary, targeting the exact
# real production paths (BIND9_LIVE_DIR, /var/lib/lesta/bind,
# /etc/bind/named.conf, the real system bind9 service install_bind9 already
# enabled). The resource is a throwaway zone (selftest.lesta.invalid) that
# exists only for the duration of this function: created, verified
# applied, then deleted, leaving no residue for a control plane that never
# heard of this resource to reconcile. A valid, sufficient self-test
# payload is `{domain, ttl: 14400, records: [], suspended: false}`: zero
# records is valid (the capability synthesizes its own NS/SOA and health
# check marker record), proven by the Go capability's own test suite.
run_node_health_selftest() {
    local resource_id create_idem create_corr delete_idem delete_corr payload envelope agent_out agent_status status_line

    resource_id=$(selftest_new_uuid)
    create_idem=$(selftest_new_uuid)
    create_corr=$(selftest_new_uuid)
    delete_idem=$(selftest_new_uuid)
    delete_corr=$(selftest_new_uuid)

    payload=$(json_join_object \
        "$(json_kv_str "domain" "selftest.lesta.invalid")" \
        "$(json_kv_raw "ttl" "14400")" \
        "$(json_kv_raw "records" "[]")" \
        "$(json_kv_raw "suspended" "false")")

    envelope=$(selftest_envelope "${DNS_BIND9_CAPABILITY}" create "${resource_id}" "${create_idem}" "${create_corr}" 1 "${payload}")

    agent_status=0
    agent_out=$(selftest_invoke_agent "${envelope}") || agent_status=$?

    if [ "${agent_status}" -ne 0 ]; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_create_failed "${AGENT_BINARY_DEST}" "agent exited ${agent_status}: $(printf '%s' "${agent_out}" | tr '\n' ' ')"
    fi

    status_line=$(selftest_status_from_output "${agent_out}")
    if [ "${status_line}" != "applied" ]; then
        run_node_health_selftest_delete "${DNS_BIND9_CAPABILITY}" "${resource_id}" "${payload}" "${delete_idem}" "${delete_corr}" || true
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_create_not_applied "${AGENT_BINARY_DEST}" "agent returned status=${status_line:-unknown} for create, expected applied: $(printf '%s' "${agent_out}" | tr '\n' ' ')"
    fi

    log_info "bootstrap_node_health self-test: create returned status=applied"

    if ! run_node_health_selftest_delete "${DNS_BIND9_CAPABILITY}" "${resource_id}" "${payload}" "${delete_idem}" "${delete_corr}"; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_cleanup_failed "${BIND9_LIVE_DIR}" "self-test create succeeded but the throwaway resource could not be deleted afterward"
    fi

    add_change dns.bind9.v1 installed_structural_only "${AGENT_BINARY_DEST}" "self-test create-then-delete of a throwaway zone (selftest.lesta.invalid) against the real, just-installed bind9 returned status=applied both times; remote control-plane registration is not yet built, so dns.bind9.v1 is structurally installed and health-checked but NOT YET control-plane-registered"
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
    MANIFEST_DIGEST=$(compute_manifest_digest "${BASE_MANIFEST}" "${FIREWALL_MANIFEST}" "${NODE_HEALTH_MANIFEST}" "${BIND9_MANIFEST}")

    parse_args "$@"
    validate_args

    if [ "${MODE}" = "version" ]; then
        emit_version_and_exit
    fi

    RUN_ID=$(run_generate_id)
    run_install_cleanup_trap

    log_info "starting install.sh mode=${MODE} run_id=${RUN_ID} installer_version=${SCRIPT_VERSION}"

    # --prepare-config is dispatched here, AFTER RUN_ID/the cleanup trap are
    # set up but BEFORE run_preflight: the normal preflight's own
    # preflight_check_include_line requires the include line to already be
    # present, matching nginx/install.sh's own identical ordering rationale.
    if [ "${MODE}" = "prepare-config" ]; then
        run_prepare_config
    fi

    # No mutation happens before this point, matching nginx/install.sh's own
    # ordering: log_init itself would create a directory, and
    # ensure_lesta_group would run groupadd, so both wait until after
    # run_preflight has passed.
    run_preflight

    if [ "${MODE}" = "dry-run" ]; then
        emit_dry_run_result_and_exit
    fi

    # --apply, and preflight passed: only now is any mutation permitted.
    ensure_lesta_group
    log_init
    log_info "preflight passed; beginning apply mutations"

    bootstrap_base
    bootstrap_firewall_baseline bind9 "${BIND9_MANIFEST}"
    checkpoint_write bootstrap_firewall_baseline "${MANIFEST_DIGEST}"
    install_bind9
    bootstrap_node_health

    checkpoint_remove
    release_write "${RELEASE_ID}" "${MANIFEST_DIGEST}"

    emit_apply_success_and_exit
}

main "$@"
