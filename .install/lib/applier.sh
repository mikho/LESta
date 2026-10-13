# shellcheck shell=sh
# shellcheck disable=SC3043
#
# .install/lib/applier.sh
#
# The root applier: the one process that renders and applies node configuration for the
# unprivileged lesta-agent-daemon (see agent/internal/applier and the vault's "Privileged Applier
# Split Plan"). A leaf installer whose capability has moved behind the applier calls, in order:
#
#   applier_install_unit                      once per node; idempotent
#   applier_allow_paths <capability> <path>…  the directories that capability writes
#   applier_allow_capabilities <capability> <CAP_…>…  any extra Linux capabilities it needs
#   applier_allow_syscalls <capability> <@group>…  any extra system call groups it needs
#   applier_start                             enable and (re)start the unit
#   applier_enable_capability <capability>    the switch: the daemon now hands it to the applier
#
# and, after swapping in a new agent binary, applier_restart_if_enabled before any self-test, so
# the self-test runs against the new applier.
#
# The unit is sandboxed: it can write only the paths the capabilities that moved behind it name
# (one drop-in each), has no network beyond what the tools it runs need, and keeps only the
# capabilities it uses.

APPLIER_CAPABILITIES_PATH="/etc/lesta/applier-capabilities"

# The applier runs as one or more instances, each its own process with its own sandbox and socket
# (see agent/cmd/lesta-agent/applier.go). The default instance applies the capabilities that write
# configuration files; "identity" applies the one that edits the account database, whose sandbox
# must be able to write /etc. applier_select_instance chooses which one the functions below act on.
APPLIER_UNITS="lesta-applier lesta-applier-identity lesta-applier-tenant lesta-applier-backup"

# applier_select_instance [name]: no name selects the default instance.
applier_select_instance() {
    if [ -z "${1:-}" ]; then
        APPLIER_UNIT_NAME="lesta-applier"
        APPLIER_EXEC_ARGS="applier"
        APPLIER_SOCKET="/run/lesta/applier.sock"
    else
        APPLIER_UNIT_NAME="lesta-applier-$1"
        APPLIER_EXEC_ARGS="applier $1"
        APPLIER_SOCKET="/run/lesta/applier-$1.sock"
    fi

    APPLIER_UNIT_PATH="/etc/systemd/system/${APPLIER_UNIT_NAME}.service"
    APPLIER_DROPIN_DIR="/etc/systemd/system/${APPLIER_UNIT_NAME}.service.d"
}

applier_select_instance ""

applier_install_unit() {
    {
        printf '[Unit]\nDescription=LESta privileged applier %s (applies node configuration for the agent daemon)\n' "${APPLIER_UNIT_NAME}"
        printf 'After=local-fs.target\nBefore=lesta-agent-daemon.service\n\n'
        printf '[Service]\nType=simple\n'
        cat <<'UNIT'
# No User=: a system service runs as root by default, and an explicit User=root makes systemd drop
# CAP_SETUID from the process even when the capability bounding set lists it, which breaks Exim
# (it changes user even for a configuration check).
UNIT
        printf 'ExecStart=/var/lib/lesta/agent/bin/lesta-agent %s\n' "${APPLIER_EXEC_ARGS}"
        cat <<'UNIT'
Restart=on-failure
RestartSec=2
# The instances share /run/lesta; one stopping must not remove the other's socket.
RuntimeDirectory=lesta
RuntimeDirectoryMode=0755
RuntimeDirectoryPreserve=yes
UMask=0022

# Sandbox. ReadWritePaths is extended per capability by a drop-in (applier_allow_paths).
NoNewPrivileges=yes
ProtectSystem=strict
ProtectHome=yes
PrivateTmp=yes
PrivateDevices=yes
ProtectKernelTunables=yes
ProtectKernelModules=yes
ProtectKernelLogs=yes
ProtectControlGroups=yes
ProtectClock=yes
RestrictSUIDSGID=yes
RestrictRealtime=yes
RestrictNamespaces=yes
LockPersonality=yes
SystemCallArchitectures=native
SystemCallFilter=@system-service
RestrictAddressFamilies=AF_UNIX AF_INET AF_INET6
CapabilityBoundingSet=CAP_CHOWN CAP_FOWNER CAP_DAC_OVERRIDE CAP_DAC_READ_SEARCH CAP_KILL

[Install]
WantedBy=multi-user.target
UNIT
    } > "${APPLIER_UNIT_PATH}.tmp"
    chmod 0644 "${APPLIER_UNIT_PATH}.tmp"
    chown root:root "${APPLIER_UNIT_PATH}.tmp"
    mv -f "${APPLIER_UNIT_PATH}.tmp" "${APPLIER_UNIT_PATH}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${APPLIER_UNIT_PATH}" "failed to write the applier unit"
}

# applier_allow_paths <capability> <path>…
# Lets the applier write the given paths while it applies <capability>.
applier_allow_paths() {
    local capability="$1"

    shift

    install -d -m 0755 -o root -g root "${APPLIER_DROPIN_DIR}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${APPLIER_DROPIN_DIR}" "failed to create the applier drop-in directory"

    # Each path is prefixed with "-", which systemd reads as "ignore it if it does not exist". Without
    # it a path that is gone (a package purged, a directory removed) stops the whole applier with
    # 226/NAMESPACE, and with it every capability that runs there.
    local paths=""
    local path

    for path in "$@"; do
        paths="${paths} -${path}"
    done

    printf '[Service]\nReadWritePaths=%s\n' "${paths# }" > "${APPLIER_DROPIN_DIR}/${capability}.conf.tmp"
    chmod 0644 "${APPLIER_DROPIN_DIR}/${capability}.conf.tmp"
    chown root:root "${APPLIER_DROPIN_DIR}/${capability}.conf.tmp"
    mv -f "${APPLIER_DROPIN_DIR}/${capability}.conf.tmp" "${APPLIER_DROPIN_DIR}/${capability}.conf" \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${APPLIER_DROPIN_DIR}/${capability}.conf" "failed to write the applier drop-in for ${capability}"
}

# applier_allow_capabilities <capability> <linux capability>…
# Adds Linux capabilities to what the applier keeps while it applies <capability> (the unit starts
# with a small set). nginx -t binds the listening ports, so nginx needs CAP_NET_BIND_SERVICE.
applier_allow_capabilities() {
    local capability="$1"

    shift

    install -d -m 0755 -o root -g root "${APPLIER_DROPIN_DIR}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${APPLIER_DROPIN_DIR}" "failed to create the applier drop-in directory"

    printf '[Service]\nCapabilityBoundingSet=%s\n' "$*" > "${APPLIER_DROPIN_DIR}/${capability}-capabilities.conf.tmp"
    chmod 0644 "${APPLIER_DROPIN_DIR}/${capability}-capabilities.conf.tmp"
    chown root:root "${APPLIER_DROPIN_DIR}/${capability}-capabilities.conf.tmp"
    mv -f "${APPLIER_DROPIN_DIR}/${capability}-capabilities.conf.tmp" "${APPLIER_DROPIN_DIR}/${capability}-capabilities.conf" \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${APPLIER_DROPIN_DIR}/${capability}-capabilities.conf" "failed to write the applier capabilities drop-in for ${capability}"
}

# applier_allow_syscalls <capability> <syscall group>…
# Adds system call groups to the applier's filter while it applies <capability> (the unit starts
# with @system-service, which leaves out the privileged calls: setuid, setgid and the like). Exim
# drops to its own user even for a configuration check, so mail needs @privileged.
applier_allow_syscalls() {
    local capability="$1"

    shift

    install -d -m 0755 -o root -g root "${APPLIER_DROPIN_DIR}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${APPLIER_DROPIN_DIR}" "failed to create the applier drop-in directory"

    printf '[Service]\nSystemCallFilter=%s\n' "$*" > "${APPLIER_DROPIN_DIR}/${capability}-syscalls.conf.tmp"
    chmod 0644 "${APPLIER_DROPIN_DIR}/${capability}-syscalls.conf.tmp"
    chown root:root "${APPLIER_DROPIN_DIR}/${capability}-syscalls.conf.tmp"
    mv -f "${APPLIER_DROPIN_DIR}/${capability}-syscalls.conf.tmp" "${APPLIER_DROPIN_DIR}/${capability}-syscalls.conf" \
        || fail_step "${EXIT_MUTATION_FAILURE}" write_failed "${APPLIER_DROPIN_DIR}/${capability}-syscalls.conf" "failed to write the applier system call drop-in for ${capability}"
}

# applier_start enables the unit and restarts it so it runs the binary and drop-ins now on disk.
applier_start() {
    # A failure here is a failed health check of the agent binary just installed (the applier is
    # that binary), so it rolls the binary back like a failed self-test does.
    systemctl daemon-reload || agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" systemctl_failed "" "systemctl daemon-reload failed"
    systemctl enable "${APPLIER_UNIT_NAME}" >/dev/null 2>&1 || agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" systemctl_enable_failed "${APPLIER_UNIT_PATH}" "systemctl enable ${APPLIER_UNIT_NAME} failed"
    systemctl restart "${APPLIER_UNIT_NAME}" || agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" applier_start_failed "${APPLIER_UNIT_PATH}" "systemctl restart ${APPLIER_UNIT_NAME} failed with the agent binary just installed"

    local waited=0

    while [ ! -S "${APPLIER_SOCKET}" ]; do
        waited=$((waited + 1))

        if [ "${waited}" -gt 50 ]; then
            agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" applier_socket_missing "${APPLIER_SOCKET}" "the applier started but its socket did not appear within five seconds"
        fi

        sleep 0.1
    done
}

# applier_enable_capability <capability>
# Adds the capability to the root-owned list the daemon reads, so it hands that capability to the
# applier. Removing the line moves the capability back to the daemon.
applier_enable_capability() {
    local capability="$1"

    if [ -f "${APPLIER_CAPABILITIES_PATH}" ] && grep -qxF "${capability}" "${APPLIER_CAPABILITIES_PATH}"; then
        return 0
    fi

    printf '%s\n' "${capability}" >> "${APPLIER_CAPABILITIES_PATH}"
    chmod 0644 "${APPLIER_CAPABILITIES_PATH}"
    chown root:root "${APPLIER_CAPABILITIES_PATH}"
}

# applier_restart_if_enabled restarts every applier instance after a new agent binary was installed, so the
# self-test that follows runs against the new applier. A no-op where it is not installed.
applier_restart_if_enabled() {
    local unit

    for unit in ${APPLIER_UNITS}; do
        if ! systemctl is-enabled "${unit}" >/dev/null 2>&1; then
            continue
        fi

        systemctl restart "${unit}" || agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" applier_restart_failed "/etc/systemd/system/${unit}.service" "systemctl restart ${unit} failed after installing a new agent binary generation"
    done
}

# applier_setup_tenant_instance installs and starts the "tenant" instance, which applies what works
# on the tenants' own data: the file manager, cron and the usage measurement. It writes
# /var/lib/lesta and /etc/cron.d, reads the tenants' files, and changes its filesystem identity to the
# account's own (system calls in @privileged, CAP_SETUID and CAP_SETGID). Shared by the installers
# whose capability it applies; the caller enables its own capabilities afterwards.
applier_setup_tenant_instance() {
    applier_select_instance tenant
    applier_install_unit
    applier_allow_paths tenant /var/lib/lesta /etc/cron.d
    applier_allow_capabilities tenant CAP_SETUID CAP_SETGID
    applier_allow_syscalls tenant @privileged
    applier_start
    applier_select_instance ""
}

# applier_setup_backup_instance installs and starts the "backup" instance. It reads all tenant data
# and, unlike the tenant instance, may reach the network (a copy to the owner's storage, a download
# to the control plane), which is why the two are separate processes.
applier_setup_backup_instance() {
    applier_select_instance backup
    applier_install_unit
    applier_allow_paths backup /var/lib/lesta
    applier_allow_capabilities backup CAP_SETUID CAP_SETGID
    applier_allow_syscalls backup @privileged
    applier_start
    applier_select_instance ""
}
