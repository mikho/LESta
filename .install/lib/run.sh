# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/lib/run.sh
#
# Run-scoped identity, the deterministic exit codes from
# .install/INSTALLER-CONTRACT.md's "Output and exit codes" table, and the
# single EXIT/INT/TERM cleanup trap every other lib file and install.sh
# register into instead of installing their own traps.
#
# This file only defines functions and constants; it has no side effects on
# its own, so it is safe to source in any mode (--dry-run, --apply,
# --version).

# Exit codes. Values and meanings must stay in lockstep with
# INSTALLER-CONTRACT.md; do not repurpose one for a new meaning without
# updating that document first. Exported (not just set) because their only
# consumer is install.sh, a separate file that sources this one; exporting
# is also what tells a static analysis of this file alone that they are
# intentionally used elsewhere, not dead constants.
export EXIT_OK=0
export EXIT_INVALID_INVOCATION=10
export EXIT_UNSUPPORTED_PLATFORM=11
export EXIT_PREFLIGHT_CONFLICT=12
export EXIT_VERIFICATION_FAILURE=13
export EXIT_MUTATION_FAILURE=20
export EXIT_HEALTH_FAILURE=21
export EXIT_ROLLBACK_FAILURE=22
export EXIT_INTERRUPTED=30

# run_generate_id prints a run identifier: a UTC timestamp plus this
# process's own PID. Unique enough for a log filename; never reused across
# concurrent runs on the same node.
run_generate_id() {
    printf '%s-%s\n' "$(date -u +%Y%m%dT%H%M%SZ)" "$$"
}

# Cleanup registry. _CLEANUP_TMP_DIRS is a newline-separated list of
# directories to remove; at most one disposable nginx self-test instance is
# tracked for a stop command. run_cleanup (registered once, by
# run_install_cleanup_trap) tears both down on EXIT/INT/TERM. Every other
# function only ever appends to these globals; only install.sh's top level
# calls run_install_cleanup_trap itself.
_CLEANUP_TMP_DIRS=""
_CLEANUP_NGINX_PREFIX=""
_CLEANUP_NGINX_CONF=""
_CLEANUP_NGINX_BINARY=""

# run_register_tmp_dir path
run_register_tmp_dir() {
    local dir="$1"

    if [ -z "${_CLEANUP_TMP_DIRS}" ]; then
        _CLEANUP_TMP_DIRS="${dir}"
    else
        _CLEANUP_TMP_DIRS="${_CLEANUP_TMP_DIRS}
${dir}"
    fi
}

# run_register_disposable_nginx prefix conf_path binary
# Call with all three arguments empty to clear a prior registration once the
# self-test has already stopped its own instance.
run_register_disposable_nginx() {
    _CLEANUP_NGINX_PREFIX="$1"
    _CLEANUP_NGINX_CONF="$2"
    _CLEANUP_NGINX_BINARY="${3:-nginx}"
}

# run_install_cleanup_trap registers run_cleanup on EXIT/INT/TERM. Call this
# exactly once, from install.sh's own top level, after RUN_ID is known.
run_install_cleanup_trap() {
    trap run_cleanup EXIT INT TERM
}

# run_cleanup stops any still-registered disposable nginx self-test instance,
# then removes every registered temp directory. Best-effort throughout: a
# failure tearing down one piece must never prevent the others from being
# attempted.
run_cleanup() {
    if [ -n "${_CLEANUP_NGINX_PREFIX}" ] && [ -n "${_CLEANUP_NGINX_CONF}" ]; then
        "${_CLEANUP_NGINX_BINARY}" -p "${_CLEANUP_NGINX_PREFIX}" -c "${_CLEANUP_NGINX_CONF}" -s stop >/dev/null 2>&1 || true
    fi

    if [ -n "${_CLEANUP_TMP_DIRS}" ]; then
        printf '%s\n' "${_CLEANUP_TMP_DIRS}" | while IFS= read -r dir; do
            [ -n "${dir}" ] && rm -rf "${dir}"
        done
    fi
}
