# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/lib/json.sh
#
# Hand-rolled JSON emission: printf plus sed-based escaping, no jq dependency
# on the target node. Every function here only ever prints to stdout; none
# read or write files, so this file is safe to source unconditionally.
#
# Building blocks, smallest to largest:
#   json_escape           - escape a raw string's special characters
#   json_str               - a quoted, escaped JSON string literal
#   json_kv_str             - "key":"escaped value"
#   json_kv_raw              - "key":<raw already-valid JSON>
#   json_join_object / json_join_array - join pre-built "key":value or value
#                              fragments into a compact {..}/[..]
#   json_array_from_lines    - join a newline-delimited list of pre-built
#                              value fragments into a compact [..]

# json_escape <string>
# Escapes backslash, double quote, tab, newline, and carriage return, in that
# order (backslash first, so the escape characters this function itself
# inserts are never re-escaped). Embedded literal newlines are supported: the
# second sed pass slurps the whole stream before substituting, rather than
# operating line by line.
json_escape() {
    local value="$1"

    printf '%s' "${value}" \
        | sed -e 's/\\/\\\\/g' -e 's/"/\\"/g' -e 's/\t/\\t/g' -e "s/$(printf '\r')/\\\\r/g" \
        | sed -e ':a' -e 'N' -e '$!ba' -e 's/\n/\\n/g'
}

# json_str <string> -> a quoted, escaped JSON string literal.
json_str() {
    printf '"%s"' "$(json_escape "$1")"
}

# json_kv_str <key> <value> -> "key":"escaped value" (no surrounding braces,
# no trailing comma; callers join fragments with json_join_object).
json_kv_str() {
    printf '"%s":"%s"' "$(json_escape "$1")" "$(json_escape "$2")"
}

# json_kv_raw <key> <raw_json> -> "key":<raw_json>. raw_json is inserted
# verbatim (unescaped): use this for numbers, booleans, and already-built
# nested objects/arrays, never for an unescaped free-text string.
json_kv_raw() {
    printf '"%s":%s' "$(json_escape "$1")" "$2"
}

# json_join_object <"key":value fragment> ... -> {frag,frag,...} or {} for
# zero arguments.
json_join_object() {
    if [ "$#" -eq 0 ]; then
        printf '{}'
        return 0
    fi

    local old_ifs="${IFS}"
    IFS=','
    printf '{%s}' "$*"
    IFS="${old_ifs}"
}

# json_join_array <value fragment> ... -> [frag,frag,...] or [] for zero
# arguments.
json_join_array() {
    if [ "$#" -eq 0 ]; then
        printf '[]'
        return 0
    fi

    local old_ifs="${IFS}"
    IFS=','
    printf '[%s]' "$*"
    IFS="${old_ifs}"
}

# json_array_from_lines <newline-delimited fragments, possibly empty> ->
# [frag,frag,...]. Each line of the input must already be one complete,
# single-line JSON value fragment (json_escape guarantees this: it collapses
# any embedded literal newline in a source string into a literal "\n"
# escape sequence, so a fragment built from json_kv_str/json_str never
# contains a raw newline byte). Reads the fragments back with `read` from a
# here-document rather than word-splitting a variable, so a fragment's own
# text (e.g. a path containing "*.conf") is never glob-expanded or split on
# whitespace.
json_array_from_lines() {
    local input="$1" line sep

    if [ -z "${input}" ]; then
        printf '[]'
        return 0
    fi

    printf '['
    sep=""
    while IFS= read -r line; do
        printf '%s%s' "${sep}" "${line}"
        sep=','
    done <<LINES
${input}
LINES
    printf ']'
}

# append_line <existing, possibly empty> <new> -> existing with new appended
# as its own line. Used to build up the newline-delimited lists
# json_array_from_lines expects.
append_line() {
    if [ -z "$1" ]; then
        printf '%s' "$2"
    else
        printf '%s\n%s' "$1" "$2"
    fi
}
