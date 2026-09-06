# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/lib/prepare-config.sh
#
# The mutation half of the lesta.d include-line prerequisite that
# lib/preflight.sh's own check_lesta_include_present only ever detects,
# never writes. Since Phase 4, an operator has had to hand-edit their own
# nginx.conf/named.conf/apache2.conf before their first --apply; this file
# gives each installer's own opt-in `--prepare-config` mode a shared,
# capability-agnostic way to perform that same edit automatically, instead
# of every installer hand-rolling its own sed/append logic.
#
# Depends on check_lesta_include_present (lib/preflight.sh), already sourced
# by any installer that sources this file.
#
# insert_lesta_include_if_missing <conf_path> <glob> <keyword> <line_to_insert> <insertion_mode>
#
# insertion_mode is "http_block" (nginx only: the line must land inside the
# conf file's own `http {}` block) or "append" (bind9/apache: a bare
# end-of-file append is a valid, syntactically correct place for their own
# include directive).
#
# Return code convention (every installer's own run_prepare_config wrapper
# switches on these to pick the right add_error code/message and exit code
# -- keep this list in lockstep with every caller):
#   0 - success: either the line was already present (a pure no-op, which is
#       what makes the whole --prepare-config subcommand idempotent) or it
#       was just inserted and (for http_block mode) re-verified present.
#   1 - conf_path does not exist at all. Mirrors
#       check_lesta_include_present's own status 1 exactly; this function
#       never calls add_error itself (kept a pure boolean-ish helper, like
#       check_lesta_include_present's own callers already do), so the
#       caller decides its own capability-specific message/exit code.
#   3 - http_block mode only: the conf file has no `^http {` line at all, so
#       this refuses to guess where to insert (no blind append or a
#       possibly-wrong location). No mutation happens on this path. The
#       caller should treat this as a distinct "looks non-stock, add the
#       line by hand" error, never lumped in with status 1.
#   4 - http_block mode only: the sed insertion ran without error, but the
#       defensive re-check via check_lesta_include_present immediately
#       afterward still does not see the line present. This catches sed
#       silently no-op'ing (e.g. an unexpected encoding/line-ending edge
#       case) rather than reporting false success. No such re-check exists
#       for "append" mode: a plain `printf >>` has no equivalent silent-noop
#       failure mode to guard against.
#
# Never touches, matches, or removes any other existing include/Include line
# in the conf file: append mode only ever adds a trailing line, and
# http_block mode's `sed ... a\` only ever inserts a new line after the
# `http {` match, never rewriting or deleting anything else in the file.
insert_lesta_include_if_missing() {
    local conf_path="$1" liveglob="$2" keyword="$3" line_to_insert="$4" insertion_mode="$5"
    local status=0

    check_lesta_include_present "${conf_path}" "${liveglob}" "${keyword}" || status=$?

    case "${status}" in
        0)
            return 0
            ;;
        1)
            return 1
            ;;
    esac

    case "${insertion_mode}" in
        append)
            printf '%s\n' "${line_to_insert}" >> "${conf_path}" || return 1
            return 0
            ;;
        http_block)
            if ! grep -q '^http {' "${conf_path}"; then
                return 3
            fi

            sed -i '/^http {/a\    '"${line_to_insert}" "${conf_path}" || return 1

            status=0
            check_lesta_include_present "${conf_path}" "${liveglob}" "${keyword}" || status=$?
            if [ "${status}" -ne 0 ]; then
                return 4
            fi

            return 0
            ;;
    esac
}
