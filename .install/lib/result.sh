# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/lib/result.sh
#
# The three result-accumulation builders every installer uses identically:
# add_change/add_error append a JSON fragment to the CHANGES/ERRORS globals
# each installer's own top level declares (matching install-result.schema.json's
# changes[]/errors[] item shapes), and fail_step records one error then
# immediately emits the final failed result via the calling installer's own
# emit_result_and_exit. Pure JSON-record builders: no capability-specific
# behavior at all, relocated verbatim from nginx/install.sh.
#
# Depends on json.sh (json_join_object, json_kv_str, append_line) and on
# run.sh's EXIT_* constants, both already sourced by any installer that
# sources this file, plus the CHANGES/ERRORS globals and emit_result_and_exit
# function each installer's own top level defines.

# add_change <capability> <action> <path> <detail>
add_change() {
    local frag
    frag=$(json_join_object \
        "$(json_kv_str "capability" "$1")" \
        "$(json_kv_str "action" "$2")" \
        "$(json_kv_str "path" "$3")" \
        "$(json_kv_str "detail" "$4")")
    CHANGES=$(append_line "${CHANGES}" "${frag}")
}

# add_error <code> <message> [<path>]
add_error() {
    local frag
    frag=$(json_join_object \
        "$(json_kv_str "code" "$1")" \
        "$(json_kv_str "message" "$2")" \
        "$(json_kv_str "path" "${3:-}")")
    ERRORS=$(append_line "${ERRORS}" "${frag}")
}

# fail_step <exit_code> <error_code> <path> <message>
# Records one error and immediately emits the final failed result.
fail_step() {
    add_error "$2" "$4" "$3"
    emit_result_and_exit failed "$1"
}
