# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/lib/log.sh
#
# Diagnostics on stderr, a persistent structured .jsonl log under
# /var/log/lesta/install/<run-id>.jsonl (apply mode only), and redaction at
# the write boundary before either destination ever sees a line.
#
# Dry-run never calls log_init, so LOG_PATH stays empty and log_event simply
# skips the file write: dry-run must not create any file on a bare node
# (including its own log directory), matching INSTALLER-CONTRACT.md's
# "without changing ... files" dry-run guarantee. stderr diagnostics are
# still emitted in every mode.
#
# Depends on json.sh (json_join_object, json_kv_str) and on RUN_ID being set
# by the caller before the first log_event call.

LOG_PATH=""

# log_redact <text>
# Redacts secret-shaped values at the write boundary: password/secret/token/
# api_key=<value> style key=value pairs (query strings, env-style lines), and
# userinfo embedded in a URL (scheme://user:pass@host).
log_redact() {
    printf '%s' "$1" | sed -E \
        -e 's/((^|[?&[:space:]])(password|secret|token|api_key)=)[^&[:space:]]*/\1REDACTED/gI' \
        -e 's#([a-zA-Z][a-zA-Z0-9+.-]*://)[^/@[:space:]]+@#\1REDACTED@#g'
}

# log_init creates the persistent log file for this run. Call only in
# --apply mode, after the "lesta" group exists (ensure_lesta_group in
# install.sh), so the file can be group-owned correctly from its first line.
log_init() {
    local dir="/var/log/lesta/install"

    install -d -m 0750 -o root -g lesta "${dir}"
    LOG_PATH="${dir}/${RUN_ID}.jsonl"
    : > "${LOG_PATH}"
    chmod 0640 "${LOG_PATH}"
    chown root:lesta "${LOG_PATH}" 2>/dev/null || true
}

# log_event <level> <message> [<extra "key":value fragment>]
# Always writes a human-readable line to stderr. When LOG_PATH is set (apply
# mode), also appends one redacted JSON object to the persistent log.
log_event() {
    local level="$1" message="$2" extra="${3:-}" redacted timestamp line

    redacted=$(log_redact "${message}")
    timestamp=$(date -u +%Y-%m-%dT%H:%M:%SZ)

    printf '%s [%s] %s\n' "${timestamp}" "${level}" "${redacted}" >&2

    if [ -n "${LOG_PATH}" ]; then
        if [ -n "${extra}" ]; then
            line=$(json_join_object \
                "$(json_kv_str "timestamp" "${timestamp}")" \
                "$(json_kv_str "run_id" "${RUN_ID}")" \
                "$(json_kv_str "level" "${level}")" \
                "$(json_kv_str "message" "${redacted}")" \
                "${extra}")
        else
            line=$(json_join_object \
                "$(json_kv_str "timestamp" "${timestamp}")" \
                "$(json_kv_str "run_id" "${RUN_ID}")" \
                "$(json_kv_str "level" "${level}")" \
                "$(json_kv_str "message" "${redacted}")")
        fi

        printf '%s\n' "${line}" >> "${LOG_PATH}"
    fi
}

log_info() {
    log_event info "$1" "${2:-}"
}

log_warn() {
    log_event warn "$1" "${2:-}"
}

log_error() {
    log_event error "$1" "${2:-}"
}
