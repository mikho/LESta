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

# Exported: also read directly (not just through this file's own functions)
# by install.sh, a separate sourced file.
export NGINX_CONF_PATH="/etc/nginx/nginx.conf"
export NGINX_LIVE_DIR="/etc/nginx/lesta.d"

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

# preflight_check_port_free <port>
# Fails when `ss -tln` already shows a listener bound to :port.
preflight_check_port_free() {
    local port="$1" occupied

    occupied=$(ss -H -tln 2>/dev/null | awk -v p=":${port}" '{if ($4 ~ p"$") {found=1}} END{print found+0}')
    if [ "${occupied}" -ne 0 ]; then
        add_error port_occupied "port ${port} is already in use (ss -tln shows an active listener)" ""
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

# check_lesta_include_present
# Detects the operator-managed prerequisite line using the identical
# substring logic agent/internal/capability/nginx/validate.go already uses at
# runtime (a line containing both "include" and NGINX_LIVE_DIR's "*.conf"
# glob), so the installer and the running agent can never disagree about
# whether the precondition holds.
#
# Returns 0 when present, 1 when nginx.conf does not exist at all, 2 when it
# exists but has no matching line.
check_lesta_include_present() {
    local liveglob="${NGINX_LIVE_DIR}/*.conf"

    if [ ! -f "${NGINX_CONF_PATH}" ]; then
        return 1
    fi

    if grep -F "include" "${NGINX_CONF_PATH}" | grep -F "${liveglob}" >/dev/null 2>&1; then
        return 0
    fi

    return 2
}

# preflight_check_include_line wraps check_lesta_include_present with the
# add_error call and remediation text appropriate to each failure case.
preflight_check_include_line() {
    local status=0

    check_lesta_include_present || status=$?

    case "${status}" in
        0)
            return 0
            ;;
        1)
            add_error nginx_conf_missing "nginx.conf not found at ${NGINX_CONF_PATH}; install nginx first (apt-get install -y nginx), then add this line inside its http {} block: include ${NGINX_LIVE_DIR}/*.conf; -- do not remove any other existing include lines. This installer never writes to nginx.conf itself." "${NGINX_CONF_PATH}"
            return 1
            ;;
        *)
            add_error nginx_conf_missing_include "${NGINX_CONF_PATH} exists but has no include ${NGINX_LIVE_DIR}/*.conf; line inside its http {} block. Add that exact line by hand -- do not remove any other existing include lines. This installer never writes to nginx.conf itself." "${NGINX_CONF_PATH}"
            return 1
            ;;
    esac
}

# preflight_classify_install_state -> prints a short human-readable
# classification (fresh/repair/upgrade/interrupted) based on
# CHECKPOINT_PATH and NGINX_RELEASE_PATH, for reporting only. Full
# interrupted-run recovery and upgrade machinery are out of scope this pass;
# this only has to report honestly, not act on the distinction yet.
preflight_classify_install_state() {
    local recorded_digest

    if [ -f "${CHECKPOINT_PATH}" ]; then
        printf 'interrupted (checkpoint present from a previous incomplete run)'
        return 0
    fi

    if [ -f "${NGINX_RELEASE_PATH}" ]; then
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
