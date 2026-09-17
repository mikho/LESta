#!/bin/sh
# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/scripts/install-selected.sh
#
# Combined installer: takes a comma-separated service selection, resolves
# the real dependency order from each selected service's own manifest.json
# (the same depends_on/provides graph .install/scripts/validate-contract.mjs
# already cycle-checks at CI time), and runs each selected leaf install.sh
# in that order, forwarding --dry-run/--apply/--yes and that service's own
# real flags. Replaces the manual, order-dependent repetition documented in
# the Installation Guide's own Chapter 3 (resources/docs/installation-guide.md,
# served at /docs in the running application).
#
# Also carries --prune: given the same selection, reconciles the node down
# to exactly that selection plus a fixed protected OS baseline, removing
# anything else present -- extended, on the operator's own explicit choice
# (this is the first uninstall capability this project has ever had; see
# .install/lib/uninstall.sh's own top comment), to a broader OS trim, not
# just LESta's own footprint, and allowed to proceed even when a service
# being dropped holds real tenant data, gated by one extra
# --confirm-data-loss flag per such service rather than a hard refusal.
# INSTALLER-CONTRACT.md's own "Uninstall and destructive migration are
# separate operator workflows and are never implied by --apply" is honored
# by --prune being a separate, named, off-by-default flag: plain --apply
# alone still only ever adds capabilities, exactly as before.
set -eu

# --- constants -------------------------------------------------------------

SCRIPT_VERSION="1.0.0"

# The seven services with a real, independently-runnable install.sh
# (confirmed directly, not assumed: firewall/node-health bootstrap
# automatically inside every one of these, and acme/statistics have no
# standalone script at all -- see each service's own README.md, and the
# Installation Guide's Chapter 3). Order here is canonical iteration order only, not install
# order: real install order is resolved from each manifest's own
# depends_on/provides below.
SERVICES_ALL="nginx apache bind9 mariadb cron mail backups"

SCRIPT_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
INSTALL_ROOT=$(CDPATH='' cd -- "${SCRIPT_DIR}/.." && pwd)

# shellcheck source=../lib/run.sh
. "${INSTALL_ROOT}/lib/run.sh"
# shellcheck source=../lib/json.sh
. "${INSTALL_ROOT}/lib/json.sh"
# shellcheck source=../lib/log.sh
. "${INSTALL_ROOT}/lib/log.sh"
# shellcheck source=../lib/preflight.sh
. "${INSTALL_ROOT}/lib/preflight.sh"
# shellcheck source=../lib/result.sh
. "${INSTALL_ROOT}/lib/result.sh"
# shellcheck source=../lib/firewall.sh
. "${INSTALL_ROOT}/lib/firewall.sh"
# shellcheck source=../lib/uninstall.sh
. "${INSTALL_ROOT}/lib/uninstall.sh"

BASE_MANIFEST="${INSTALL_ROOT}/base/manifest.json"
PROTECTED_PACKAGES_FILE="${INSTALL_ROOT}/base/protected-packages.txt"

# --- globals (all pre-declared for `set -u` safety) -------------------------

MODE=""
YES=0
PRUNE=0
SERVICES_RAW=""
SELECTED=""
WEB_SERVER=""
WEB_PROFILE=""
MAIL_HOSTNAME=""
OFFLINE_BUNDLE_NGINX=""
OFFLINE_BUNDLE_APACHE=""
OFFLINE_BUNDLE_BIND9=""
OFFLINE_BUNDLE_MARIADB=""
OFFLINE_BUNDLE_CRON=""
OFFLINE_BUNDLE_MAIL=""
CONFIRM_DATA_LOSS=""
RUN_ID=""
CHANGES=""
ERRORS=""
ORDER=""

# --- usage / argument parsing ----------------------------------------------

usage() {
    cat <<'USAGE' >&2
Usage: install-selected.sh --dry-run|--apply|--version --services <list> [options]

  --dry-run                    Resolve order and report what would run/change.
                                No mutation.
  --apply                      Run the resolved installers for real. Requires
                                --yes.
  --version                    Print this script's own version and exit.
  --services <a,b,c>            Required with --dry-run/--apply. Comma-
                                separated, from: nginx,apache,bind9,mariadb,
                                cron,mail,backups. (agent-daemon is a separate,
                                always-manual enrollment step -- see the
                                Installation Guide's Chapter 2 --
                                and is never selectable here.) A dependency
                                already really installed on this node
                                satisfies a selected service's own
                                requirement even when its own provider isn't
                                part of THIS selection: adding mail to an
                                already-nginx-and-bind9 node only needs
                                --services mail, nginx/bind9 do not need to
                                be re-listed or re-run.
  --yes                        Required with --apply: non-interactive
                                confirmation, forwarded to every leaf
                                installer this run invokes.
  --web-server <profile>       Forwarded to nginx/install.sh when nginx is
                                selected (required in that case).
  --web-profile <profile>      Forwarded to apache/install.sh when apache is
                                selected standalone.
  --mail-hostname <fqdn>       Forwarded to mail/install.sh when mail is
                                selected (required in that case).
  --offline-bundle <svc>=<path>
                                Forwards --offline-bundle <path> to that one
                                service's own install.sh. Repeatable (one
                                bundle is built per service by
                                build-release.sh, never a combined bundle, so
                                this is scoped per service rather than a
                                single shared path). <svc> is one of
                                nginx,apache,bind9,mariadb,cron,mail.
  --prune                      Reconcile the node down to exactly --services
                                plus the fixed protected baseline
                                (base/protected-packages.txt), removing
                                everything else present, including non-LESta
                                OS packages. Valid with both --dry-run (report
                                only) and --apply (acts). Never implied by
                                plain --apply.
  --confirm-data-loss <svc>    Required once per stateful service (mariadb,
                                mail, backups) that --prune --apply would
                                remove, i.e. one that is currently present but
                                not in --services. Repeatable. Missing one
                                fails closed before any mutation.
  --help                       Print this message.
USAGE
}

fail_invocation() {
    printf 'install-selected.sh: %s\n' "$1" >&2
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
            --prune)
                PRUNE=1
                shift
                ;;
            --services)
                [ "$#" -ge 2 ] || fail_invocation "--services requires an argument"
                SERVICES_RAW="$2"
                shift 2
                ;;
            --web-server)
                [ "$#" -ge 2 ] || fail_invocation "--web-server requires an argument"
                WEB_SERVER="$2"
                shift 2
                ;;
            --web-profile)
                [ "$#" -ge 2 ] || fail_invocation "--web-profile requires an argument"
                WEB_PROFILE="$2"
                shift 2
                ;;
            --mail-hostname)
                [ "$#" -ge 2 ] || fail_invocation "--mail-hostname requires an argument"
                MAIL_HOSTNAME="$2"
                shift 2
                ;;
            --offline-bundle)
                [ "$#" -ge 2 ] || fail_invocation "--offline-bundle requires an argument (svc=path)"
                case "$2" in
                    nginx=*) OFFLINE_BUNDLE_NGINX="${2#nginx=}" ;;
                    apache=*) OFFLINE_BUNDLE_APACHE="${2#apache=}" ;;
                    bind9=*) OFFLINE_BUNDLE_BIND9="${2#bind9=}" ;;
                    mariadb=*) OFFLINE_BUNDLE_MARIADB="${2#mariadb=}" ;;
                    cron=*) OFFLINE_BUNDLE_CRON="${2#cron=}" ;;
                    mail=*) OFFLINE_BUNDLE_MAIL="${2#mail=}" ;;
                    *) fail_invocation "--offline-bundle must be <svc>=<path> with svc one of nginx,apache,bind9,mariadb,cron,mail (got: $2)" ;;
                esac
                shift 2
                ;;
            --confirm-data-loss)
                [ "$#" -ge 2 ] || fail_invocation "--confirm-data-loss requires an argument"
                CONFIRM_DATA_LOSS=$(append_line "${CONFIRM_DATA_LOSS}" "$2")
                shift 2
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

# service_is_selected <svc> -> exit 0 if svc is in SELECTED
service_is_selected() {
    case " ${SELECTED} " in
        *" $1 "*) return 0 ;;
        *) return 1 ;;
    esac
}

confirmed_data_loss_for() {
    printf '%s\n' "${CONFIRM_DATA_LOSS}" | grep -Fxq "$1"
}

validate_args() {
    local svc

    case "${MODE}" in
        version) return 0 ;;
        dry-run | apply) ;;
        *) fail_invocation "exactly one of --dry-run, --apply, or --version is required" ;;
    esac

    if [ "${MODE}" = "apply" ] && [ "${YES}" -ne 1 ]; then
        fail_invocation "--apply requires --yes"
    fi

    [ -n "${SERVICES_RAW}" ] || fail_invocation "--services is required with --dry-run/--apply"

    SELECTED=$(printf '%s' "${SERVICES_RAW}" | tr ',' ' ')
    for svc in ${SELECTED}; do
        case " ${SERVICES_ALL} " in
            *" ${svc} "*) ;;
            *) fail_invocation "unknown service in --services: ${svc} (must be one of: ${SERVICES_ALL})" ;;
        esac
    done

    if service_is_selected nginx && service_is_selected apache; then
        fail_invocation "select nginx alone with --web-server both instead of selecting nginx and apache together: nginx/install.sh already dispatches to apache/install.sh itself for that profile, and running both here would apply apache twice"
    fi

    if service_is_selected nginx && [ -z "${WEB_SERVER}" ]; then
        fail_invocation "--web-server is required when nginx is selected"
    fi

    if service_is_selected mail && [ -z "${MAIL_HOSTNAME}" ]; then
        fail_invocation "--mail-hostname is required when mail is selected"
    fi
}

# --- manifest-graph helpers --------------------------------------------

svc_manifest() {
    printf '%s/services/%s/manifest.json' "${INSTALL_ROOT}" "$1"
}

# capability_provider <token> -> the one service in SERVICES_ALL whose own
# manifest provides <token>, or empty if none of the seven selectable
# services provides it at all (meaning it is either always bootstrapped
# automatically -- firewall.baseline.v1, node.health.v1, base.*.v1 -- or an
# out-of-band prerequisite a real selectable service's own preflight already
# checks directly, like mail's tls.acme.v1: no selectable service "provides"
# a certificate, so there is nothing this orchestrator could tell an
# operator to additionally select).
capability_provider() {
    local token="$1" svc

    for svc in ${SERVICES_ALL}; do
        if manifest_extract_array "$(svc_manifest "${svc}")" provides | grep -Fxq "${token}"; then
            printf '%s' "${svc}"
            return 0
        fi
    done

    # Not found is an expected, common, non-fatal outcome (checked by every
    # caller via [ -n "${provider}" ]), so this must return 0: this function
    # is always used as provider=$(capability_provider ...), and a nonzero
    # exit from the right-hand side of a plain assignment aborts the whole
    # script under `set -e` -- confirmed directly, not assumed, since a bare
    # trailing `for` loop whose last iteration's own condition is false
    # would otherwise leave exactly that nonzero status behind.
    return 0
}

# resolve_order -> prints SELECTED in real dependency order, one per line, or
# fails closed (exit 12) naming exactly which selected service is missing
# exactly which other selectable service, before any mutation. A dependency
# is also considered satisfied when its own provider is already really
# installed on this node (uninstall_service_present, lib/uninstall.sh --
# the same real dpkg/systemd/directory presence check --prune already
# relies on), even when that provider isn't part of the current --services
# selection at all: an operator adding mail to an already-nginx-and-bind9
# node only needs --services mail, not --services nginx,bind9,mail again.
resolve_order() {
    local remaining="${SELECTED}" ordered="" progressed svc dep provider missing_lines=""
    local ok first_missing new_remaining r

    while [ -n "$(printf '%s' "${remaining}" | tr -d '[:space:]')" ]; do
        progressed=0
        new_remaining="${remaining}"

        for svc in ${remaining}; do
            ok=1
            first_missing=""

            for dep in $(manifest_extract_array "$(svc_manifest "${svc}")" depends_on); do
                provider=$(capability_provider "${dep}")
                [ -n "${provider}" ] || continue
                [ "${provider}" = "${svc}" ] && continue

                case " ${ordered} " in
                    *" ${provider} "*) continue ;;
                esac

                uninstall_service_present "${provider}" && continue

                ok=0
                first_missing="${dep} (select ${provider}, or install it separately first)"
                break
            done

            if [ "${ok}" -eq 1 ]; then
                ordered="${ordered} ${svc}"
                progressed=1
                new_remaining=$(
                    for r in ${new_remaining}; do
                        [ "${r}" = "${svc}" ] || printf '%s ' "${r}"
                    done
                )
            else
                missing_lines=$(append_line "${missing_lines}" "${svc} needs ${first_missing}")
            fi
        done

        remaining="${new_remaining}"

        if [ "${progressed}" -eq 0 ]; then
            printf '%s\n' "${missing_lines}" | while IFS= read -r line; do
                [ -n "${line}" ] && add_error missing_dependency "${line}" ""
            done
            emit_result_and_exit failed "${EXIT_PREFLIGHT_CONFLICT}"
        fi

        missing_lines=""
    done

    printf '%s\n' "${ordered}" | tr -s ' ' '\n' | sed '/^$/d'
}

# --- per-service invocation --------------------------------------------

# service_args <svc> -> the extra args (beyond --dry-run|--apply, --yes)
# this service's own install.sh needs, one per line (so an embedded space in
# a value, e.g. a bundle path, is never word-split).
service_args() {
    case "$1" in
        nginx)
            printf '%s\n' --web-server
            printf '%s\n' "${WEB_SERVER}"
            if [ -n "${OFFLINE_BUNDLE_NGINX}" ]; then
                printf '%s\n' --offline-bundle
                printf '%s\n' "${OFFLINE_BUNDLE_NGINX}"
            fi
            ;;
        apache)
            if [ -n "${WEB_PROFILE}" ]; then
                printf '%s\n' --web-profile
                printf '%s\n' "${WEB_PROFILE}"
            fi
            if [ -n "${OFFLINE_BUNDLE_APACHE}" ]; then
                printf '%s\n' --offline-bundle
                printf '%s\n' "${OFFLINE_BUNDLE_APACHE}"
            fi
            ;;
        bind9)
            if [ -n "${OFFLINE_BUNDLE_BIND9}" ]; then
                printf '%s\n' --offline-bundle
                printf '%s\n' "${OFFLINE_BUNDLE_BIND9}"
            fi
            ;;
        mariadb)
            if [ -n "${OFFLINE_BUNDLE_MARIADB}" ]; then
                printf '%s\n' --offline-bundle
                printf '%s\n' "${OFFLINE_BUNDLE_MARIADB}"
            fi
            ;;
        cron)
            if [ -n "${OFFLINE_BUNDLE_CRON}" ]; then
                printf '%s\n' --offline-bundle
                printf '%s\n' "${OFFLINE_BUNDLE_CRON}"
            fi
            ;;
        mail)
            printf '%s\n' --mail-hostname
            printf '%s\n' "${MAIL_HOSTNAME}"
            if [ -n "${OFFLINE_BUNDLE_MAIL}" ]; then
                printf '%s\n' --offline-bundle
                printf '%s\n' "${OFFLINE_BUNDLE_MAIL}"
            fi
            ;;
        backups) ;;
    esac
}

# run_service <svc> -> execs services/<svc>/install.sh with --dry-run/--apply
# (+ --yes on apply) plus service_args, letting its own stdout/stderr and
# exit code pass straight through (its own JSONL line and log are the
# authoritative record for that service; this orchestrator never re-parses
# or re-emits it). Returns that installer's own exit code.
run_service() {
    local svc="$1" script mode_flag arg

    script="${INSTALL_ROOT}/services/${svc}/install.sh"
    mode_flag="--${MODE}"

    set -- "${mode_flag}"
    [ "${MODE}" = "apply" ] && set -- "$@" --yes

    while IFS= read -r arg; do
        [ -n "${arg}" ] && set -- "$@" "${arg}"
    done <<ARGS
$(service_args "${svc}")
ARGS

    log_info "install-selected: running ${svc}/install.sh $*"
    sh "${script}" "$@"
}

# --- prune -----------------------------------------------------------------

# dropped_services -> every service in SERVICES_ALL that is present on the
# node right now (uninstall_service_present, lib/uninstall.sh) but not in
# SELECTED, one per line. These are candidates --prune would remove.
dropped_services() {
    local svc

    for svc in ${SERVICES_ALL}; do
        service_is_selected "${svc}" && continue
        if uninstall_service_present "${svc}"; then
            printf '%s\n' "${svc}"
        fi
    done

    # Explicit and unconditional: finding zero dropped services is a normal,
    # non-error outcome, and this function is always used as
    # dropped=$(dropped_services) -- a nonzero exit from the last command in
    # a function's body aborts the whole script under `set -e` even inside a
    # plain assignment, confirmed directly (not assumed) via the exact same
    # bare-trailing-for-loop shape capability_provider had above.
    return 0
}

run_prune() {
    local dropped svc pkgs candidates dest detail unconfirmed=""

    dropped=$(dropped_services)

    for svc in ${dropped}; do
        if uninstall_service_is_stateful "${svc}"; then
            confirmed_data_loss_for "${svc}" || unconfirmed=$(append_line "${unconfirmed}" "${svc}")
        fi
    done

    candidates=$(uninstall_compute_candidates "${PROTECTED_PACKAGES_FILE}" "${SELECTED}")

    if [ "${MODE}" = "dry-run" ]; then
        for svc in ${dropped}; do
            detail="currently present, not in --services; would stop/disable its unit(s) and purge its own packages"
            if uninstall_service_is_stateful "${svc}"; then
                detail="${detail}, quarantining its real data root rather than deleting it (requires --confirm-data-loss ${svc})"
            fi
            add_change prune would_remove_service "${svc}" "${detail}"
        done

        printf '%s\n' "${candidates}" | while IFS= read -r svc; do
            [ -n "${svc}" ] && add_change prune would_purge_package "${svc}" "installed, not protected, not required by any selected service"
        done

        if [ -n "${unconfirmed}" ]; then
            printf '%s\n' "${unconfirmed}" | while IFS= read -r svc; do
                [ -n "${svc}" ] && add_error missing_confirm_data_loss "${svc} holds real data and would be removed; pass --confirm-data-loss ${svc} to actually apply this" ""
            done
        fi

        return 0
    fi

    # --apply: fail closed before any mutation if any stateful dropped
    # service still lacks its own explicit confirmation.
    if [ -n "${unconfirmed}" ]; then
        printf '%s\n' "${unconfirmed}" | while IFS= read -r svc; do
            [ -n "${svc}" ] && add_error missing_confirm_data_loss "${svc} holds real data and would be removed by --prune; pass --confirm-data-loss ${svc} to proceed" ""
        done
        emit_result_and_exit failed "${EXIT_PREFLIGHT_CONFLICT}"
    fi

    for svc in ${dropped}; do
        dest=""
        if uninstall_service_is_stateful "${svc}"; then
            dest=$(uninstall_quarantine_data_root "${svc}") || dest=""
        fi

        uninstall_remove_service "$(svc_manifest "${svc}")" "${svc}" "$([ -n "${dest}" ] && printf 1 || printf 0)"

        if [ -n "${dest}" ]; then
            add_change prune removed_service_data_quarantined "${dest}" "${svc}'s own real data root moved here rather than deleted; the node's own capability for ${svc} must still be removed by hand from the admin app"
        else
            add_change prune removed_service "${svc}" "stopped/disabled and packages purged; no real data root to quarantine"
        fi
    done

    pkgs=$(uninstall_compute_candidates "${PROTECTED_PACKAGES_FILE}" "${SELECTED}")
    if [ -n "${pkgs}" ]; then
        # shellcheck disable=SC2046
        apt-get purge -y $(printf '%s' "${pkgs}" | tr '\n' ' ') >/dev/null 2>&1 || true
        add_change prune purged_os_packages "" "$(printf '%s' "${pkgs}" | tr '\n' ' ')"
    fi
}

# --- result emission ---------------------------------------------------

emit_result_and_exit() {
    local status="$1" exit_code="$2" changes_json errors_json services_json order_json confirm_json result

    changes_json=$(json_array_from_lines "${CHANGES}")
    errors_json=$(json_array_from_lines "${ERRORS}")

    services_json=$(json_array_from_lines "$(printf '%s\n' "${SELECTED}" | tr -s ' ' '\n' | sed '/^$/d' | while IFS= read -r s; do json_str "${s}"; done)")
    order_json=$(json_array_from_lines "$(printf '%s\n' "${ORDER}" | tr -s ' ' '\n' | sed '/^$/d' | while IFS= read -r s; do json_str "${s}"; done)")
    confirm_json=$(json_array_from_lines "$(printf '%s\n' "${CONFIRM_DATA_LOSS}" | sed '/^$/d' | while IFS= read -r s; do json_str "${s}"; done)")

    result=$(json_join_object \
        "$(json_kv_str "schema_version" "1")" \
        "$(json_kv_str "installer" "install-selected")" \
        "$(json_kv_str "mode" "${MODE:-unset}")" \
        "$(json_kv_str "status" "${status}")" \
        "$(json_kv_raw "exit_code" "${exit_code}")" \
        "$(json_kv_raw "services_requested" "${services_json}")" \
        "$(json_kv_raw "order" "${order_json}")" \
        "$(json_kv_raw "prune" "$([ "${PRUNE}" -eq 1 ] && printf true || printf false)")" \
        "$(json_kv_raw "confirm_data_loss" "${confirm_json}")" \
        "$(json_kv_raw "changes" "${changes_json}")" \
        "$(json_kv_raw "errors" "${errors_json}")")

    printf '%s\n' "${result}"
    exit "${exit_code}"
}

emit_version_and_exit() {
    MODE="version"
    printf '%s\n' "$(json_join_object "$(json_kv_str "schema_version" "1")" "$(json_kv_str "installer" "install-selected")" "$(json_kv_str "version" "${SCRIPT_VERSION}")")"
    exit "${EXIT_OK}"
}

# --- preflight -----------------------------------------------------------
#
# Minimal here on purpose: root and OS/arch only. Every leaf installer this
# script goes on to invoke already runs its own full preflight (capacity,
# port conflicts, identity, its own manual-prerequisite checks) before its
# own mutation -- duplicating that here would only drift out of sync with
# each leaf's own real checks.

run_preflight() {
    local os_id os_version_id supported

    if [ "$(id -u)" -ne 0 ]; then
        add_error not_root "install-selected.sh must run as root (uid 0)" ""
        emit_result_and_exit failed "${EXIT_UNSUPPORTED_PLATFORM}"
    fi

    os_id=$(preflight_os_release_field ID)
    os_version_id=$(preflight_os_release_field VERSION_ID)
    supported=$(manifest_extract_array "${BASE_MANIFEST}" "supported_ubuntu")

    if [ "${os_id}" != "ubuntu" ] || ! printf '%s\n' "${supported}" | grep -Fxq "${os_version_id}"; then
        add_error unsupported_os "detected ${os_id:-unknown} ${os_version_id:-unknown}; supported: $(printf '%s' "${supported}" | tr '\n' ' ')" "/etc/os-release"
        emit_result_and_exit failed "${EXIT_UNSUPPORTED_PLATFORM}"
    fi
}

# --- main -------------------------------------------------------------

main() {
    local svc rc

    parse_args "$@"
    validate_args

    if [ "${MODE}" = "version" ]; then
        emit_version_and_exit
    fi

    RUN_ID=$(run_generate_id)
    run_install_cleanup_trap

    run_preflight

    ORDER=$(resolve_order)

    if [ "${MODE}" = "apply" ]; then
        log_init
    fi
    log_info "install-selected: run_id=${RUN_ID} mode=${MODE} services=${SELECTED} order=$(printf '%s' "${ORDER}" | tr '\n' ' ') prune=${PRUNE}"

    if [ "${PRUNE}" -eq 1 ]; then
        run_prune
    fi

    # Every selected service's own real install.sh is invoked here in both
    # modes, forwarded --dry-run or --apply exactly as given to this script:
    # a combined --dry-run must perform each leaf's own complete preflight
    # (INSTALLER-CONTRACT.md's own "Dry-run performs manifest verification
    # and complete preflight"), not a synthetic placeholder report, so an
    # operator who runs --dry-run first genuinely learns about a missing
    # include line or a port conflict before ever reaching --apply.
    for svc in ${ORDER}; do
        rc=0
        run_service "${svc}" || rc=$?
        if [ "${rc}" -ne 0 ]; then
            add_error leaf_installer_failed "${svc}/install.sh exited ${rc} in ${MODE} mode; every already-succeeded service in this run stays applied (apply mode) or was only checked, never mutated (dry-run mode). Fix the reported blocker and re-run install-selected.sh with the same --services: every leaf installer is idempotent and will skip or repair what already succeeded." ""
            emit_result_and_exit failed "${rc}"
        fi
    done

    for svc in ${ORDER}; do
        if [ "${MODE}" = "apply" ]; then
            add_change "${svc}" applied "${svc}/install.sh" "ran to completion"
        else
            add_change "${svc}" verified "${svc}/install.sh" "dry-run completed with no reported change beyond what --prune above already reported"
        fi
    done

    if [ "${MODE}" = "apply" ]; then
        emit_result_and_exit applied "${EXIT_OK}"
    fi
    emit_result_and_exit would_change "${EXIT_OK}"
}

main "$@"
