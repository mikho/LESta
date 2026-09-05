# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/lib/daemon.sh
#
# Writes the daemon's own config file and systemd unit, and activates it.
# Pure node-local file/systemctl operations; no network calls (those live in
# lib/enrollment.sh). Mirrors agent/cmd/lesta-agent/main.go's own
# daemonProductionConfig(): CONFIG_PATH's literal value here must stay in
# lockstep with that function's own configPath constant.

DAEMON_CONFIG_PATH="/etc/lesta/agent/daemon-config.json"
DAEMON_UNIT_PATH="/etc/systemd/system/lesta-agent-daemon.service"

# daemon_write_config <control_plane_url> <node_uuid> <heartbeat_interval_seconds> <protocol_version>
daemon_write_config() {
    local control_plane_url="$1" node_uuid="$2" heartbeat_interval="$3" protocol_version="$4"
    local payload

    payload=$(json_join_object \
        "$(json_kv_str "control_plane_url" "${control_plane_url}")" \
        "$(json_kv_str "node_uuid" "${node_uuid}")" \
        "$(json_kv_raw "heartbeat_interval_seconds" "${heartbeat_interval}")" \
        "$(json_kv_str "protocol_version" "${protocol_version}")")

    install -d -m 0750 -o lesta-agent -g lesta /etc/lesta/agent 2>/dev/null || true
    printf '%s\n' "${payload}" > "${DAEMON_CONFIG_PATH}.tmp"
    chmod 0640 "${DAEMON_CONFIG_PATH}.tmp"
    chown lesta-agent:lesta "${DAEMON_CONFIG_PATH}.tmp" 2>/dev/null || true
    mv -f "${DAEMON_CONFIG_PATH}.tmp" "${DAEMON_CONFIG_PATH}"
}

# daemon_install_systemd_unit writes the fixed systemd unit that supervises
# "lesta-agent daemon", running as the existing lesta-agent system user (the
# same identity every other installer's own node-health phase already
# creates via lib/agent.sh, never a new identity of its own).
daemon_install_systemd_unit() {
    cat > "${DAEMON_UNIT_PATH}" <<'UNIT'
[Unit]
Description=LESta agent daemon (heartbeat and execution reporting)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=lesta-agent
Group=lesta
ExecStart=/var/lib/lesta/agent/bin/lesta-agent daemon
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
UNIT
    chmod 0644 "${DAEMON_UNIT_PATH}"
}

# daemon_enable_and_start reloads systemd's own unit cache and enables
# +starts the daemon unit.
daemon_enable_and_start() {
    systemctl daemon-reload
    systemctl enable --now lesta-agent-daemon
}
