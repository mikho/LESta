# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/lib/preflight.sh
#
# Preflight check functions run identically for --dry-run and --apply: only
# --apply proceeds past preflight into mutation. Each preflight_check_*
# function returns 0/1 and, on failure, calls add_error itself (defined in
# install.sh) with a specific code/message/path so callers only need to track
# whether anything failed.
#
# Also holds the small manifest-array/field readers used to keep values
# (supported Ubuntu releases, ports, artifact digests) sourced from the
# actual manifest.json files rather than duplicated as literals in shell.
#
# NGINX_CONF_PATH/NGINX_LIVE_DIR are no longer declared here: each installer
# now sets its own local equivalents (e.g. nginx/install.sh's own
# NGINX_CONF_PATH/NGINX_LIVE_DIR, bind9/install.sh's own NAMED_CONF_PATH/
# BIND9_LIVE_DIR) before calling check_lesta_include_present, below.

# manifest_extract_array <file> <field> -> one array item per line, quotes
# and surrounding whitespace stripped. Only works for a simple flat
# string/number array declared on a single line, which is how every manifest
# in this repository is formatted.
manifest_extract_array() {
    local file="$1" field="$2"

    sed -n "s/.*\"${field}\"[[:space:]]*:[[:space:]]*\[\([^]]*\)\].*/\1/p" "${file}" \
        | tr ',' '\n' \
        | sed -e 's/^[[:space:]]*"\{0,1\}//' -e 's/"\{0,1\}[[:space:]]*$//' -e '/^[[:space:]]*$/d'
}

# manifest_extract_ports <file> -> one port number per line, read from
# ports[].port regardless of protocol/direction.
manifest_extract_ports() {
    local file="$1"

    grep -o '"port"[[:space:]]*:[[:space:]]*[0-9]\{1,5\}' "${file}" | grep -o '[0-9]\{1,5\}$'
}

# manifest_artifact_sha256 <file> <artifact-name> -> the sha256 hex digest
# recorded for that name in artifacts[], or empty if not found. Assumes
# artifacts[] is declared on a single line with no nested braces inside an
# entry, matching this repository's manifest formatting.
manifest_artifact_sha256() {
    local file="$1" name="$2" line

    line=$(grep -o '"artifacts"[[:space:]]*:[[:space:]]*\[.*\]' "${file}" || true)
    [ -n "${line}" ] || return 0

    printf '%s' "${line}" \
        | grep -o "{[^}]*\"name\"[[:space:]]*:[[:space:]]*\"${name}\"[^}]*}" \
        | sed -n 's/.*"sha256"[[:space:]]*:[[:space:]]*"\([a-f0-9]*\)".*/\1/p'
}

# preflight_os_release_field <FIELD> -> the value of FIELD from
# /etc/os-release, read by sourcing it in a subshell (its format is designed
# to be sourced by a POSIX shell).
preflight_os_release_field() {
    local field="$1"

    (
        # /etc/os-release only exists on the target Ubuntu node this phase
        # runs on, never in this repository, so a static analysis of this
        # file alone cannot follow it (shellcheck: SC1091). Its format is
        # designed to be sourced by a POSIX shell.
        . /etc/os-release 2>/dev/null
        eval "printf '%s' \"\${${field}:-}\""
    )
}

# preflight_check_capacity <path>
# Fails (and records why) when the filesystem backing path has less than
# 1 GiB free or more than 95% of its inodes used.
preflight_check_capacity() {
    local path="$1" avail_kb iuse_pct

    avail_kb=$(df -Pk "${path}" | awk 'NR==2{print $4}')
    if [ "${avail_kb}" -lt 1048576 ]; then
        add_error insufficient_disk "filesystem backing ${path} has ${avail_kb} KiB free; need at least 1048576 KiB (1 GiB)" "${path}"
        return 1
    fi

    iuse_pct=$(df -Pi "${path}" | awk 'NR==2{print $5}' | tr -d '%')
    if [ "${iuse_pct}" -gt 95 ]; then
        add_error insufficient_inodes "filesystem backing ${path} is at ${iuse_pct}% inode usage; need at least 5% free" "${path}"
        return 1
    fi

    return 0
}

# preflight_check_port_free <port> <protocol> <expected_owner>
# protocol is "tcp" or "udp". Fails when ss shows a listener bound to :port
# whose owner isn't expected_owner. A rerun against an already-bootstrapped
# node finds its own, already-running service holding these exact ports --
# that is convergence, not a conflict, and
# preflight_check_conflicting_packages already rejects the one thing that
# would make an expected_owner-held port suspicious anyway (a competing
# package coexisting on the node). Anything else holding the port (an
# unrelated process, or expected_owner not yet reflected in `ss` for some
# other reason) still fails closed.
#
# A single port commonly has more than one matching ss line (an IPv4 and an
# IPv6 listener, both expected_owner): every matching line's owner must be
# expected_owner, not just one of them, so this walks all of them rather
# than sampling the first.
#
# Loopback-only listeners (127.0.0.0/8, ::1) are skipped outright, never
# considered a conflict regardless of owner: every service this installer
# family bootstraps (nginx, named) binds a wildcard/public address, and on
# Linux a specific-address bind (e.g. systemd-resolved's stub resolver at
# 127.0.0.53:53, present by default on every Ubuntu 24.04/26.04 node) and a
# later wildcard bind on the same port from a different process coexist at
# the socket layer without conflict; only a genuine second wildcard-or-
# public-address listener on the port is a real conflict.
preflight_check_port_free() {
    local port="$1" protocol="$2" expected_owner="$3" ss_flag line owner addr bad=0 bad_owner=""

    case "${protocol}" in
        tcp) ss_flag="-tlnp" ;;
        udp) ss_flag="-ulnp" ;;
        *) ss_flag="-tlnp" ;;
    esac

    while IFS= read -r line; do
        [ -n "${line}" ] || continue

        addr=$(printf '%s' "${line}" | awk '{print $4}')
        addr="${addr%:*}"
        case "${addr}" in
            127.* | '[::1]' | ::1)
                continue
                ;;
        esac

        owner=$(printf '%s' "${line}" | sed -n 's/.*users:(("\([^"]*\)".*/\1/p')

        if [ "${owner}" != "${expected_owner}" ]; then
            bad=1
            bad_owner="${owner:-an unidentified process}"
        fi
    done <<LINES
$(ss -H "${ss_flag}" 2>/dev/null | awk -v p=":${port}" '$4 ~ p"$"')
LINES

    if [ "${bad}" -ne 0 ]; then
        add_error port_occupied "port ${port}/${protocol} is already in use by '${bad_owner}' (ss ${ss_flag})" ""
        return 1
    fi

    return 0
}

# preflight_check_conflicting_packages
# Fails when apache2/lighttpd is installed (dpkg -l) or apache2.service is
# active. This installer refuses to displace an operator-managed web server.
preflight_check_conflicting_packages() {
    if dpkg -l 2>/dev/null | grep -E '^ii[[:space:]]+(apache2|lighttpd)\b' >/dev/null 2>&1; then
        add_error conflicting_package "apache2 or lighttpd is already installed (dpkg -l); this installer refuses to displace an existing web server package" ""
        return 1
    fi

    if command -v systemctl >/dev/null 2>&1 && systemctl is-active --quiet apache2 2>/dev/null; then
        add_error conflicting_service "apache2.service is active; this installer refuses to displace a running web server" ""
        return 1
    fi

    return 0
}

# preflight_check_lesta_identity
# Fails when a lesta-agent user already exists with a home or shell that
# does not match what bootstrap_base would create, so this installer never
# silently adopts an unrelated pre-existing account.
preflight_check_lesta_identity() {
    local expected_home="/var/lib/lesta" expected_shell="/usr/sbin/nologin" home shell

    if getent passwd lesta-agent >/dev/null 2>&1; then
        home=$(getent passwd lesta-agent | awk -F: '{print $6}')
        shell=$(getent passwd lesta-agent | awk -F: '{print $7}')

        if [ "${home}" != "${expected_home}" ] || [ "${shell}" != "${expected_shell}" ]; then
            add_error conflicting_identity "an existing lesta-agent user has home=${home} shell=${shell}, which does not match what this installer would create (home=${expected_home} shell=${expected_shell})" "/etc/passwd"
            return 1
        fi
    fi

    return 0
}

# check_lesta_include_present <conf_path> <glob>
# Detects the operator-managed prerequisite line using the identical
# substring logic agent/internal/capability/nginx/validate.go (and bind9's
# own equivalent) already uses at runtime (a line containing both "include"
# and the live-dir glob), so the installer and the running agent can never
# disagree about whether the precondition holds. Pure detection only: each
# installer wraps this with its own add_error call and remediation text,
# since that text (and the error codes used) differ per installer.
#
# Returns 0 when present, 1 when conf_path does not exist at all, 2 when it
# exists but has no matching line.
check_lesta_include_present() {
    local conf_path="$1" liveglob="$2"

    if [ ! -f "${conf_path}" ]; then
        return 1
    fi

    if grep -F "include" "${conf_path}" | grep -F "${liveglob}" >/dev/null 2>&1; then
        return 0
    fi

    return 2
}

# preflight_classify_install_state -> prints a short human-readable
# classification (fresh/repair/upgrade/interrupted) based on
# CHECKPOINT_PATH and RELEASE_PATH (each installer's own local values --
# see lib/checkpoint.sh's own top comment), for reporting only. Full
# interrupted-run recovery and upgrade machinery are out of scope this pass;
# this only has to report honestly, not act on the distinction yet.
preflight_classify_install_state() {
    local recorded_digest

    if [ -f "${CHECKPOINT_PATH}" ]; then
        printf 'interrupted (checkpoint present from a previous incomplete run)'
        return 0
    fi

    if [ -f "${RELEASE_PATH}" ]; then
        recorded_digest=$(release_read_digest || true)
        if [ "${recorded_digest}" = "${MANIFEST_DIGEST}" ]; then
            printf 'repair (already installed at this exact manifest digest)'
        else
            printf 'upgrade (a different manifest digest is already installed: %s)' "${recorded_digest}"
        fi
        return 0
    fi

    printf 'fresh (no prior installation recorded)'
}
