# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/lib/uninstall.sh
#
# The reverse of every leaf-service install.sh, built here for the first
# time: nothing in this project has ever uninstalled a capability before
# install-selected.sh's own --prune mode (.install/INSTALLER-CONTRACT.md's
# own "Uninstall and destructive migration are separate operator workflows
# and are never implied by --apply" had, until now, nothing to name).
#
# Each real service's own package list, systemd unit(s), and whether it
# holds real, non-reconstructible tenant state are NOT read from
# manifest.json: every manifest.json in this repository declares
# packages: [] (confirmed by reading all seven directly -- the field exists
# in the schema but nothing populates it; offline-bundle.sh's own dependency
# closure is computed dynamically via apt-cache depends --recurse instead,
# never from this field). The small, explicit tables below are this file's
# own single source of truth instead, deliberately kept in one place and
# reviewable, mirroring protected-packages.txt's own reviewability rather
# than being buried in per-service logic.
#
# "Stateful" here means: this service's own real data cannot be
# reconstructed from Laravel's own control-plane database the way every
# other capability's config can (confirmed architecture from this project's
# own restore/resync work: web/DNS/cron desired state is always fully
# re-derivable from Laravel, so removing and later re-adding nginx/apache/
# bind9/cron loses nothing real). mariadb (its tenant instance only), mail
# (real received mail), and backups (the encrypted artifacts themselves,
# this node's own recovery safety net) are the three exceptions.

# uninstall_service_packages <service_id> -> real apt package names, one
# per line, or nothing if the service installs no package of its own.
#
# mariadb is deliberately absent here: mariadb-server/mariadb-client are
# shared between the tenant instance (removable) and the control-plane
# instance (this application's own database, never removable, never a
# NodeCapability an operator selects in the first place) -- purging these
# packages to remove the tenant instance would take the control-plane
# instance down with it. Removing the tenant instance never touches the
# package, only mariadb@tenant.service and the tenant's own files (see
# uninstall_remove_service below).
uninstall_service_packages() {
    case "$1" in
        nginx) printf '%s\n' nginx ;;
        apache) printf '%s\n' apache2 ;;
        bind9)
            printf '%s\n' bind9
            printf '%s\n' bind9-utils
            ;;
        cron) printf '%s\n' cron ;;
        mail)
            printf '%s\n' exim4-daemon-heavy
            printf '%s\n' dovecot-imapd
            printf '%s\n' dovecot-lmtpd
            printf '%s\n' dovecot-sieve
            ;;
        backups | mariadb) return 0 ;;
        *) return 0 ;;
    esac
}

# uninstall_service_units <service_id> -> real systemd unit name(s), one per
# line, or nothing if the service has no daemon of its own (backups is pure
# Go with no listener at all, per its own README).
uninstall_service_units() {
    case "$1" in
        nginx) printf '%s\n' nginx ;;
        apache) printf '%s\n' apache2 ;;
        # named.service is the real unit; bind9.service is only a systemd
        # alias (see bind9/install.sh's own identical comment).
        bind9) printf '%s\n' named ;;
        cron) printf '%s\n' cron ;;
        mail)
            printf '%s\n' exim4
            printf '%s\n' dovecot
            ;;
        # Only the tenant instance -- mariadb.service (control-plane) must
        # never be stopped or disabled by this file under any circumstance.
        mariadb) printf '%s\n' mariadb@tenant.service ;;
        *) return 0 ;;
    esac
}

# uninstall_service_is_stateful <service_id> -> exit 0 (true) for a service
# whose own real data cannot be reconstructed from Laravel's own database.
uninstall_service_is_stateful() {
    case "$1" in
        mariadb | mail | backups) return 0 ;;
        *) return 1 ;;
    esac
}

# uninstall_service_data_root <service_id> -> the one real data directory to
# quarantine (never delete outright) for a stateful service. Empty for a
# non-stateful service.
uninstall_service_data_root() {
    case "$1" in
        # Tenant only -- never /var/lib/lesta/mariadb/control-plane.
        mariadb) printf '%s\n' /var/lib/lesta/mariadb/tenant ;;
        mail) printf '%s\n' /var/lib/lesta/mail ;;
        backups) printf '%s\n' /var/lib/lesta/backups ;;
        *) return 0 ;;
    esac
}

# uninstall_service_owned_roots <service_id> -> every other owned path (not
# the data root above) this service's own manifest.json declares, minus the
# mariadb control-plane paths, which install-selected.sh's own uninstall
# path must never remove even when only the tenant instance is dropped.
uninstall_service_owned_roots() {
    local manifest="$1"
    local service_id="$2"

    manifest_extract_array "${manifest}" owned_roots | while IFS= read -r path; do
        [ -n "${path}" ] || continue

        if [ "${service_id}" = "mariadb" ]; then
            case "${path}" in
                *control-plane*) continue ;;
            esac
        fi

        printf '%s\n' "${path}"
    done
}

# uninstall_is_protected_package <name> -> exit 0 (true) if <name> is listed
# in protected-packages.txt, exact or by its own trailing "*" prefix match.
uninstall_is_protected_package() {
    local name="$1" protected_file="$2" line

    while IFS= read -r line; do
        case "${line}" in
            "" | "#"*) continue ;;
            *"*")
                case "${name}" in
                    "${line%\*}"*) return 0 ;;
                esac
                ;;
            *)
                [ "${name}" = "${line}" ] && return 0
                ;;
        esac
    done < "${protected_file}"

    return 1
}

# uninstall_service_present <service_id> -> exit 0 (true) if this service
# looks currently installed on this node: any of its own real packages is
# installed (dpkg-query), or, for the two services with no package of their
# own, its own real data root exists. Used by install-selected.sh's own
# --prune to decide which non-selected services are actual removal
# candidates rather than services that were simply never installed at all.
uninstall_service_present() {
    local service_id="$1" pkg data_root

    for pkg in $(uninstall_service_packages "${service_id}"); do
        if dpkg-query -W -f '${Status}' "${pkg}" 2>/dev/null | grep -q ' installed$'; then
            return 0
        fi
    done

    case "${service_id}" in
        mariadb)
            [ -d /var/lib/lesta/mariadb/tenant ] && return 0
            ;;
        backups)
            [ -d /var/lib/lesta/backups ] && return 0
            ;;
    esac

    data_root=$(uninstall_service_data_root "${service_id}")
    [ -n "${data_root}" ] && [ -e "${data_root}" ] && return 0

    return 1
}

# uninstall_compute_candidates <protected_file> <space-separated selected
# service ids> -> every currently-installed package that is neither
# protected nor required by a selected service, one per line. Pure
# read-only enumeration: no mutation, safe to call in --dry-run.
uninstall_compute_candidates() {
    local protected_file="$1" selected="$2" svc keep_packages="" pkg

    for svc in ${selected}; do
        keep_packages="${keep_packages} $(uninstall_service_packages "${svc}" | tr '\n' ' ')"
    done

    dpkg-query -W -f '${Package}\n' 2>/dev/null | while IFS= read -r pkg; do
        [ -n "${pkg}" ] || continue
        uninstall_is_protected_package "${pkg}" "${protected_file}" && continue

        case " ${keep_packages} " in
            *" ${pkg} "*) continue ;;
        esac

        printf '%s\n' "${pkg}"
    done
}

# uninstall_remove_service <manifest> <service_id> <data_root_quarantined:
# 0|1> -> stops/disables the service's own unit(s), purges its own real
# packages (mariadb: never purges mariadb-server/mariadb-client, see
# uninstall_service_packages), removes its firewall port registration and
# re-renders the shared table, and removes every owned root except a
# stateful service's own real data root, which the caller must already have
# quarantined (data_root_quarantined = 1) before calling this, or confirmed
# absent (data_root_quarantined = 0, meaning there was nothing to move).
# Never calls fail_step itself: mutation failures here are reported by the
# caller via add_error, since a partial prune (one service removed, the next
# failing) must still emit a real, honest result rather than aborting mid
# function with no record of what already changed.
uninstall_remove_service() {
    local manifest="$1" service_id="$2" data_root_quarantined="$3"
    local unit pkg data_root

    for unit in $(uninstall_service_units "${service_id}"); do
        systemctl disable --now "${unit}" >/dev/null 2>&1 || true
    done

    pkg=$(uninstall_service_packages "${service_id}" | tr '\n' ' ')
    if [ -n "${pkg}" ]; then
        # shellcheck disable=SC2086
        apt-get purge -y ${pkg} >/dev/null 2>&1 || true
    fi

    if [ -d "${FIREWALL_PORTS_DIR:-/var/lib/lesta/firewall/ports.d}" ]; then
        rm -f "${FIREWALL_PORTS_DIR:-/var/lib/lesta/firewall/ports.d}/${service_id}.ports"
        firewall_render_and_apply || true
    fi

    data_root=$(uninstall_service_data_root "${service_id}")

    uninstall_service_owned_roots "${manifest}" "${service_id}" | while IFS= read -r path; do
        [ -n "${path}" ] || continue
        [ "${path}" = "${data_root}" ] && continue
        rm -rf "${path}"
    done

    if [ -n "${data_root}" ] && [ "${data_root_quarantined}" != "1" ] && [ -e "${data_root}" ]; then
        rm -rf "${data_root}"
    fi
}

# uninstall_quarantine_data_root <service_id> -> moves a stateful service's
# own real data root to /var/lib/lesta/<service>/removed-<UTC timestamp>/
# rather than deleting it, and prints the destination path (empty, and a
# non-zero exit, if there was nothing to quarantine). Must be called before
# uninstall_remove_service so that function's own owned-roots cleanup never
# races the move.
uninstall_quarantine_data_root() {
    local service_id="$1" data_root dest

    data_root=$(uninstall_service_data_root "${service_id}")
    [ -n "${data_root}" ] || return 1
    [ -e "${data_root}" ] || return 1

    dest="/var/lib/lesta/${service_id}/removed-$(date -u +%Y%m%dT%H%M%SZ)"
    mkdir -p "$(dirname "${dest}")"
    mv "${data_root}" "${dest}" || return 1

    printf '%s\n' "${dest}"
}
