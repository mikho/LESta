# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/lib/firewall.sh
#
# The deny-by-default nftables baseline, shared by every leaf-service
# installer. Extracted out of nginx/install.sh's own original
# bootstrap_firewall_baseline (which rendered the entire `table inet lesta`
# from scratch every run, reading ports only from its own manifest): that
# behavior silently clobbers every other service's already-open ports the
# moment a second leaf-service installer runs its own firewall phase on the
# same node, since `nft -f` replaces a named table's entire content rather
# than merging it.
#
# The fix is an incrementally-updated per-service port registry
# (FIREWALL_PORTS_DIR/<service_id>.ports, one small fragment file per
# service ever bootstrapped on this node): each installer only ever writes
# its own fragment, then the render step unions every fragment currently on
# disk before regenerating the one shared nftables table. A rerun of any
# single installer only ever touches its own fragment; every other
# service's already-registered ports survive untouched.

FIREWALL_DIR="/etc/lesta/firewall"
FIREWALL_PORTS_DIR="${FIREWALL_DIR}/ports.d"
NFT_TABLE_PATH="${FIREWALL_DIR}/lesta.nft"
FIREWALL_UNIT_PATH="/etc/systemd/system/lesta-firewall.service"

# manifest_extract_port_specs <file> -> "<protocol> <port>" lines, one per
# ports[] entry whose direction is "inbound". Mirrors
# manifest_artifact_sha256's own grep -o technique (lib/preflight.sh) for
# pulling individual {...} objects out of a single-line JSON array: every
# manifest.json in this repository declares its ports[] array on one line,
# with no nested braces inside an entry.
manifest_extract_port_specs() {
    local file="$1" array_line obj protocol port direction

    array_line=$(grep -o '"ports"[[:space:]]*:[[:space:]]*\[.*\]' "${file}" || true)
    [ -n "${array_line}" ] || return 0

    printf '%s' "${array_line}" | grep -o '{[^}]*}' | while IFS= read -r obj; do
        protocol=$(printf '%s' "${obj}" | sed -n 's/.*"protocol"[[:space:]]*:[[:space:]]*"\([a-z]*\)".*/\1/p')
        port=$(printf '%s' "${obj}" | sed -n 's/.*"port"[[:space:]]*:[[:space:]]*\([0-9]\{1,5\}\).*/\1/p')
        direction=$(printf '%s' "${obj}" | sed -n 's/.*"direction"[[:space:]]*:[[:space:]]*"\([a-z]*\)".*/\1/p')

        if [ "${direction}" = "inbound" ] && [ -n "${protocol}" ] && [ -n "${port}" ]; then
            printf '%s %s\n' "${protocol}" "${port}"
        fi
    done
}

# firewall_register_service_ports <service_id> <manifest_file>
# (Re)writes FIREWALL_PORTS_DIR/<service_id>.ports with this service's own
# port specs only, atomically (write to .tmp, mv). Never reads or touches
# any other service's fragment. Idempotent: re-running with an unchanged
# manifest produces byte-identical output.
firewall_register_service_ports() {
    local service_id="$1" manifest_file="$2" dest

    dest="${FIREWALL_PORTS_DIR}/${service_id}.ports"

    manifest_extract_port_specs "${manifest_file}" > "${dest}.tmp"
    chmod 0640 "${dest}.tmp"
    chown root:lesta "${dest}.tmp" 2>/dev/null || true
    mv -f "${dest}.tmp" "${dest}"
}

# firewall_render_and_apply
# Unions every *.ports fragment currently present under FIREWALL_PORTS_DIR
# (every service ever bootstrapped on this node), de-duplicates (preserving
# order of first appearance), groups by protocol, renders a
# `tcp dport { ... } accept;` line only when at least one tcp port is
# registered, a `udp dport { ... } accept;` line only when at least one udp
# port is registered (fixing the original renderer's TCP-only limitation),
# validates the resulting nft file with `nft -c`, then applies it with
# `nft -f`. Also (re)writes and enables the lesta-firewall.service systemd
# unit (idempotent, same content every run).
#
# The add_change message below reports a flat, protocol-agnostic list of
# every registered port (mirroring the original nginx-only renderer's own
# message exactly, byte for byte, when nginx is the only fragment
# registered), separate from the tcp/udp split used for the actual nft
# rule rendering.
firewall_render_and_apply() {
    local fragment protocol port
    local all_raw="" tcp_raw="" udp_raw=""
    local port_rules tcp_rendered="" udp_rendered="" out

    if [ -d "${FIREWALL_PORTS_DIR}" ]; then
        for fragment in "${FIREWALL_PORTS_DIR}"/*.ports; do
            [ -f "${fragment}" ] || continue

            while IFS=' ' read -r protocol port; do
                if [ -z "${protocol}" ] || [ -z "${port}" ]; then
                    continue
                fi

                all_raw=$(append_line "${all_raw}" "${port}")
                case "${protocol}" in
                    tcp) tcp_raw=$(append_line "${tcp_raw}" "${port}") ;;
                    udp) udp_raw=$(append_line "${udp_raw}" "${port}") ;;
                esac
            done < "${fragment}"
        done
    fi

    all_raw=$(printf '%s\n' "${all_raw}" | awk '!seen[$0]++ && length($0) > 0')
    tcp_raw=$(printf '%s\n' "${tcp_raw}" | awk '!seen[$0]++ && length($0) > 0')
    udp_raw=$(printf '%s\n' "${udp_raw}" | awk '!seen[$0]++ && length($0) > 0')

    port_rules=$(printf '%s' "${all_raw}" | tr '\n' ',' | sed -e 's/,/, /g' -e 's/, $//')
    [ -n "${tcp_raw}" ] && tcp_rendered=$(printf '%s' "${tcp_raw}" | tr '\n' ',' | sed -e 's/,/, /g' -e 's/, $//')
    [ -n "${udp_raw}" ] && udp_rendered=$(printf '%s' "${udp_raw}" | tr '\n' ',' | sed -e 's/,/, /g' -e 's/, $//')

    # Declare the table+chain first (a no-op if they already exist with the
    # same type/hook/priority/policy, which this script always declares
    # identically), then explicitly flush the chain before adding the
    # current rule set. Without the flush, `nft -f` does not replace a
    # chain's content on repeat invocations, it only ever ADDS rules on
    # top of whatever the kernel already holds -- a real, previously
    # latent bug inherited unchanged from nginx's own original
    # bootstrap_firewall_baseline (Phase 4), never caught until this
    # phase's own explicit `nft list table inet lesta` CI verification
    # step actually looked at the rendered ruleset and found the accept
    # rules duplicated once per prior apply/re-apply in the same CI job
    # (nginx apply, nginx re-apply, bind9 apply: three copies). nft
    # evaluates one `-f` script as a single atomic transaction, so the
    # flush statement below validates and applies correctly even on a
    # completely fresh node where the table doesn't exist yet: it's
    # created by the first declaration earlier in this same file, then
    # flushed (a no-op on an already-empty chain), then repopulated.
    {
        printf 'table inet lesta {\n'
        printf '    chain input {\n'
        printf '        type filter hook input priority 0; policy drop;\n'
        printf '    }\n'
        printf '}\n'
        printf 'flush chain inet lesta input\n'
        printf 'table inet lesta {\n'
        printf '    chain input {\n'
        printf '        ct state established,related accept;\n'
        printf '        iif lo accept;\n'
        printf '        icmp type echo-request accept;\n'
        printf '        tcp dport 22 accept;\n'
        if [ -n "${tcp_rendered}" ]; then
            printf '        tcp dport { %s } accept;\n' "${tcp_rendered}"
        fi
        if [ -n "${udp_rendered}" ]; then
            printf '        udp dport { %s } accept;\n' "${udp_rendered}"
        fi
        printf '    }\n'
        printf '}\n'
    } > "${NFT_TABLE_PATH}.tmp"
    chmod 0640 "${NFT_TABLE_PATH}.tmp"
    chown root:lesta "${NFT_TABLE_PATH}.tmp" 2>/dev/null || true

    if ! out=$(nft -c -f "${NFT_TABLE_PATH}.tmp" 2>&1); then
        rm -f "${NFT_TABLE_PATH}.tmp"
        add_error nft_validation_failed "${out}" "${NFT_TABLE_PATH}"
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi

    mv -f "${NFT_TABLE_PATH}.tmp" "${NFT_TABLE_PATH}"

    if ! out=$(nft -f "${NFT_TABLE_PATH}" 2>&1); then
        add_error nft_apply_failed "${out}" "${NFT_TABLE_PATH}"
        emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
    fi
    add_change firewall.baseline.v1 applied "${NFT_TABLE_PATH}" "deny-by-default inet lesta table loaded (ssh 22, ${port_rules})"

    {
        printf '[Unit]\n'
        printf 'Description=LESta firewall baseline (nftables)\n'
        printf 'After=network-pre.target\n'
        printf 'Before=network.target\n'
        printf '\n'
        printf '[Service]\n'
        printf 'Type=oneshot\n'
        printf 'ExecStart=/usr/sbin/nft -f %s\n' "${NFT_TABLE_PATH}"
        printf 'RemainAfterExit=yes\n'
        printf '\n'
        printf '[Install]\n'
        printf 'WantedBy=multi-user.target\n'
    } > "${FIREWALL_UNIT_PATH}.tmp"
    chmod 0644 "${FIREWALL_UNIT_PATH}.tmp"
    mv -f "${FIREWALL_UNIT_PATH}.tmp" "${FIREWALL_UNIT_PATH}"

    systemctl daemon-reload || fail_step "${EXIT_MUTATION_FAILURE}" systemctl_daemon_reload_failed "${FIREWALL_UNIT_PATH}" "systemctl daemon-reload failed"
    systemctl enable lesta-firewall.service >/dev/null || fail_step "${EXIT_MUTATION_FAILURE}" systemctl_enable_failed "${FIREWALL_UNIT_PATH}" "systemctl enable lesta-firewall.service failed"
    add_change firewall.baseline.v1 enabled "${FIREWALL_UNIT_PATH}" "systemd oneshot unit installed and enabled for boot-time reload"
}

# bootstrap_firewall_baseline <service_id> <manifest_file>
# The shared entry point every installer calls: ensure FIREWALL_DIR and
# FIREWALL_PORTS_DIR exist (mode 0750 root:lesta), register this service's
# own ports, then render and apply the union of every service's ports.
# Callers still call checkpoint_write themselves after this returns
# (checkpoint behavior stays per-installer, unchanged).
bootstrap_firewall_baseline() {
    local service_id="$1" manifest_file="$2"

    log_info "bootstrap_firewall_baseline: rendering deny-by-default nftables table"

    install -d -m 0750 -o root -g lesta "${FIREWALL_DIR}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${FIREWALL_DIR}" "failed to create ${FIREWALL_DIR}"
    install -d -m 0750 -o root -g lesta "${FIREWALL_PORTS_DIR}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${FIREWALL_PORTS_DIR}" "failed to create ${FIREWALL_PORTS_DIR}"
    add_change firewall.baseline.v1 ensured "${FIREWALL_DIR}" "directory present, mode 0750 root:lesta"

    firewall_register_service_ports "${service_id}" "${manifest_file}"
    firewall_render_and_apply

    log_info "bootstrap_firewall_baseline complete"
}
