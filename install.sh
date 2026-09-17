#!/bin/sh
# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# install.sh (repository root)
#
# This is NOT a leaf-service installer and is NOT governed by
# .install/INSTALLER-CONTRACT.md's "Supply chain"/"Preflight" rules for
# system mutation -- it never installs a package, creates a system user, or
# touches firewall/service state. Its only job is fetching the small subset
# of this repository a fresh node actually needs (.install/ and the one
# prebuilt agent binary at agent/dist/lesta-agent-linux-amd64) via a real git
# partial clone plus cone-mode sparse-checkout, instead of an operator
# cloning the entire control-plane application (Laravel app code, the full
# Go agent source tree, tests, vendor/node_modules, docs) onto infrastructure
# that will only ever run .install's own scripts. Confirmed directly (not
# assumed): a cone-mode sparse-checkout of .install and agent/dist also pulls
# in each selected directory's own repository-root and agent/-root files
# (cone mode's documented behavior), never the full application tree
# (app/, resources/, database/, tests/, vendor/, node_modules/,
# agent/internal, agent/cmd stay absent).
#
# Per INSTALLER-CONTRACT.md's own "No curl | bash, dynamic remote script
# execution, or unpinned network fetch" rule: this file is meant to be
# fetched and read BEFORE it is run, never piped directly from a network
# fetch into a shell. See README.md / the Installation Guide's own Chapter 2
# for the documented, explicit two-step (download, then run) command.
#
# After this script succeeds, continue with the Installation Guide's
# Chapter 3 (enroll the node) from inside the directory it just created.
set -eu

SCRIPT_VERSION="1.0.0"

DEFAULT_REPO_URL="https://github.com/mikho/LESta.git"
DEFAULT_REF="main"
DEFAULT_DEST="/opt/lesta"

# Mirrors .install/lib/run.sh's own EXIT_* numbering exactly, for an operator
# who has already read the Installation Guide and knows this convention --
# this script has no dependency on that file (it doesn't exist on the node
# yet, which is the entire reason this script exists), so the values are
# repeated here rather than sourced.
EXIT_OK=0
EXIT_INVALID_INVOCATION=10
EXIT_UNSUPPORTED_PLATFORM=11
EXIT_MUTATION_FAILURE=20

REPO_URL="${DEFAULT_REPO_URL}"
REF="${DEFAULT_REF}"
DEST="${DEFAULT_DEST}"
MODE=""

usage() {
    cat <<USAGE >&2
Usage: install.sh --dry-run|--apply|--version [--repo <url>] [--ref <ref>] [--dest <path>] [--yes] [--help]

  --dry-run       Report what would be fetched. No network access, no mutation.
  --apply         Fetch .install/ and agent/dist/ for real. Requires --yes.
  --version       Print this script's own version and exit.
  --repo <url>    Git URL to fetch from. Default: ${DEFAULT_REPO_URL}
  --ref <ref>     Branch, tag, or commit to check out. Default: ${DEFAULT_REF}
  --dest <path>   Directory to create (or update, if it already exists and
                  looks like a checkout this script made). Default: ${DEFAULT_DEST}
  --yes           Required with --apply: non-interactive confirmation.
  --help          Print this message.

Only .install/ and agent/dist/lesta-agent-linux-amd64 end up populated with
real content at <dest> (cone-mode sparse-checkout also leaves a handful of
harmless top-level repository files present -- never app/, resources/,
database/, tests/, vendor/, node_modules/, or the Go agent's own source).
USAGE
}

fail_invocation() {
    printf 'install.sh: %s\n' "$1" >&2
    usage
    exit "${EXIT_INVALID_INVOCATION}"
}

fail_mutation() {
    printf 'install.sh: %s\n' "$1" >&2
    exit "${EXIT_MUTATION_FAILURE}"
}

parse_args() {
    if [ "$#" -eq 0 ]; then
        fail_invocation "exactly one of --dry-run, --apply, or --version is required"
    fi

    YES=0

    while [ "$#" -gt 0 ]; do
        case "$1" in
            --dry-run)
                [ -z "${MODE}" ] || fail_invocation "only one of --dry-run/--apply/--version may be given"
                MODE="dry-run"
                ;;
            --apply)
                [ -z "${MODE}" ] || fail_invocation "only one of --dry-run/--apply/--version may be given"
                MODE="apply"
                ;;
            --version)
                [ -z "${MODE}" ] || fail_invocation "only one of --dry-run/--apply/--version may be given"
                MODE="version"
                ;;
            --repo)
                [ "$#" -ge 2 ] || fail_invocation "--repo requires a value"
                REPO_URL="$2"
                shift
                ;;
            --repo=*)
                REPO_URL="${1#--repo=}"
                ;;
            --ref)
                [ "$#" -ge 2 ] || fail_invocation "--ref requires a value"
                REF="$2"
                shift
                ;;
            --ref=*)
                REF="${1#--ref=}"
                ;;
            --dest)
                [ "$#" -ge 2 ] || fail_invocation "--dest requires a value"
                DEST="$2"
                shift
                ;;
            --dest=*)
                DEST="${1#--dest=}"
                ;;
            --yes)
                YES=1
                ;;
            --help)
                usage
                exit "${EXIT_OK}"
                ;;
            *)
                fail_invocation "unrecognized argument: $1"
                ;;
        esac
        shift
    done

    if [ -z "${MODE}" ]; then
        fail_invocation "exactly one of --dry-run, --apply, or --version is required"
    fi

    if [ "${MODE}" = "apply" ] && [ "${YES}" -ne 1 ]; then
        fail_invocation "--apply requires --yes"
    fi
}

require_git() {
    if ! command -v git >/dev/null 2>&1; then
        printf 'install.sh: git is required and was not found on PATH. Install it first (apt-get install -y git).\n' >&2
        exit "${EXIT_UNSUPPORTED_PLATFORM}"
    fi

    # Partial clone (--filter=blob:none) and cone-mode sparse-checkout both
    # need a real, non-ancient git -- confirmed available since 2.25 (both
    # features shipped together in that release). Ubuntu 24.04/26.04 ship
    # well past this (2.43+), so this only ever fires on something unusual.
    git_version=$(git --version | awk '{print $3}')
    git_major=$(printf '%s' "${git_version}" | cut -d. -f1)
    git_minor=$(printf '%s' "${git_version}" | cut -d. -f2)

    if [ "${git_major}" -lt 2 ] || { [ "${git_major}" -eq 2 ] && [ "${git_minor}" -lt 25 ]; }; then
        printf 'install.sh: git %s found, but 2.25 or newer is required for --filter=blob:none and cone-mode sparse-checkout.\n' "${git_version}" >&2
        exit "${EXIT_UNSUPPORTED_PLATFORM}"
    fi
}

run_dry_run() {
    printf 'Would fetch ref "%s" of %s into %s:\n' "${REF}" "${REPO_URL}" "${DEST}"
    if [ -d "${DEST}/.git" ]; then
        printf '  %s already exists with its own .git -- would update it in place (fetch + checkout), not re-clone.\n' "${DEST}"
    else
        printf '  %s does not exist yet -- would create it via a partial clone + sparse-checkout of .install and agent/dist.\n' "${DEST}"
    fi
    printf '  No network access made, nothing written. Re-run with --apply --yes to actually fetch.\n'
}

configure_sparse_checkout() {
    git -C "${DEST}" sparse-checkout init --cone \
        || fail_mutation "git sparse-checkout init failed in ${DEST}"
    git -C "${DEST}" sparse-checkout set .install agent/dist \
        || fail_mutation "git sparse-checkout set failed in ${DEST}"
}

run_fresh_checkout() {
    printf 'install.sh: cloning %s (ref %s) into %s (.install and agent/dist only)\n' "${REPO_URL}" "${REF}" "${DEST}"

    # No --branch here on purpose: --branch only accepts a real ref name, not an
    # arbitrary commit SHA, on every transport (confirmed directly, including
    # against a purely local clone). The initial clone only needs to set up a
    # real git repository and remote at ${DEST}; the explicit fetch right below
    # (identical to run_update_existing's own) is what actually resolves
    # ${REF} -- a branch, a tag, or a full commit SHA all work identically,
    # since GitHub's own remote (confirmed directly) allows fetching an exact
    # reachable commit SHA, not only named refs.
    git clone --no-checkout --depth 1 --filter=blob:none "${REPO_URL}" "${DEST}" \
        || fail_mutation "git clone of ${REPO_URL} failed"

    git -C "${DEST}" fetch --depth 1 origin "${REF}" \
        || fail_mutation "git fetch of ${REF} from origin failed in ${DEST}"

    configure_sparse_checkout

    git -c advice.detachedHead=false -C "${DEST}" checkout FETCH_HEAD \
        || fail_mutation "git checkout FETCH_HEAD failed in ${DEST}"
}

run_update_existing() {
    printf 'install.sh: %s already looks like a checkout this script made -- updating it in place\n' "${DEST}"

    git -C "${DEST}" fetch --depth 1 origin "${REF}" \
        || fail_mutation "git fetch of ${REF} from origin failed in ${DEST}"

    # Re-assert the sparse-checkout paths every run, not just on first clone:
    # a later version of this script may need a third directory, and a plain
    # re-run should be how an existing checkout picks that up, matching this
    # project's own "re-running is how you pick up an update, not a separate
    # upgrade command" convention for every real installer.
    configure_sparse_checkout

    git -c advice.detachedHead=false -C "${DEST}" checkout FETCH_HEAD \
        || fail_mutation "git checkout FETCH_HEAD failed in ${DEST}"
}

run_apply() {
    require_git

    if [ -d "${DEST}/.git" ]; then
        run_update_existing
    else
        [ -e "${DEST}" ] && fail_mutation "${DEST} already exists and is not a git checkout -- remove it or choose a different --dest"
        run_fresh_checkout
    fi

    printf 'install.sh: done. .install/ and agent/dist/ are now present at %s.\n' "${DEST}"
    printf 'Next: %s/.install/services/agent-daemon/install.sh --apply --yes --node-uuid <node_uuid> --enrollment-token <token> --control-plane-url https://your-lesta-domain.example\n' "${DEST}"
    printf '(see the Installation Guide, Chapter 3, for issuing that enrollment token first)\n'
}

main() {
    parse_args "$@"

    case "${MODE}" in
        version)
            printf 'install.sh %s\n' "${SCRIPT_VERSION}"
            exit "${EXIT_OK}"
            ;;
        dry-run)
            run_dry_run
            exit "${EXIT_OK}"
            ;;
        apply)
            run_apply
            exit "${EXIT_OK}"
            ;;
    esac
}

main "$@"
