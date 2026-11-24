# shellcheck shell=sh
# shellcheck disable=SC3043
#
# .install/lib/bootstrap.sh
#
# Phase 1 of every leaf-service installer: the lesta group, the lesta-agent user and the three
# base directories every service shares. Capability-agnostic, so one copy serves them all; a
# service that needs more at this point (php-fpm's self-test group) adds its own step after
# bootstrap_base in its main().

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
    install -d -m 0751 -o root -g lesta /var/log/lesta || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/log/lesta "failed to create /var/log/lesta"
    add_change base.os.v1 ensured /etc/lesta "directory present, mode 0751 root:lesta"
    add_change base.layout.v1 ensured /var/lib/lesta "directory present, mode 0750 root:lesta"
    add_change base.layout.v1 ensured /var/log/lesta "directory present, mode 0751 root:lesta (nginx workers pass through to reopen their logs)"

    update-ca-certificates >/dev/null 2>&1 || true
    add_change base.tls.v1 refreshed /etc/ssl/certs "update-ca-certificates run"
    bootstrap_trim_os_services

    checkpoint_write bootstrap_base "${MANIFEST_DIGEST}"
    log_info "bootstrap_base complete"
}

# bootstrap_trim_os_services stops Ubuntu services a hosting node never uses, to keep its memory
# small (vault: "Minimal memory footprint"): ModemManager (cellular modems), udisks2 (desktop disk
# management), multipathd (SAN multipath, unless the node has multipath devices) and
# networkd-dispatcher (network event hooks, unless the node has hooks of its own), and fwupd's
# daily metadata refresh timer, which is what starts fwupd (fwupd itself exits after five idle
# minutes, so it is left alone). Each is disabled and stopped, never removed:
# no package is touched, `systemctl enable --now <unit>` brings one back, and `fwupdmgr` still
# works by hand. Left alone: unattended-upgrades (a security function) and snapd, which something
# at boot starts again through its socket even when disabled, so only removing the package would
# keep it off, and LESta does not remove OS packages it does not own. Only a unit that is enabled or running is changed, so a re-run records nothing new.
bootstrap_trim_os_services() {
    local unit

    for unit in ModemManager.service udisks2.service fwupd-refresh.timer; do
        bootstrap_stop_unit "${unit}" || true
    done

    # multipathd.socket is static (it cannot be disabled, and udev may open it again during the same
    # boot); with the service disabled it is not started at boot, so it is only stopped alongside.
    if ! dmsetup ls --target multipath 2>/dev/null | grep -qv '^No devices found'; then
        if bootstrap_stop_unit multipathd.service; then
            systemctl stop multipathd.socket >/dev/null 2>&1 || true
        fi
    fi

    if ! find /etc/networkd-dispatcher -type f 2>/dev/null | grep -q .; then
        bootstrap_stop_unit networkd-dispatcher.service || true
    fi
}

# bootstrap_stop_unit <unit> disables and stops one unit when it is enabled or active (return 0); a
# unit the node does not have, or that is already off, is left as it is (return 1).
bootstrap_stop_unit() {
    local unit="$1"

    systemctl cat "${unit}" >/dev/null 2>&1 || return 1

    if systemctl is-enabled --quiet "${unit}" 2>/dev/null || systemctl is-active --quiet "${unit}" 2>/dev/null; then
        systemctl disable --now "${unit}" >/dev/null 2>&1 || systemctl stop "${unit}" >/dev/null 2>&1 || true
        add_change base.os.v1 disabled "${unit}" "${unit} disabled and stopped: a hosting node does not use it (systemctl enable --now ${unit} brings it back)"
        return 0
    fi

    return 1
}
