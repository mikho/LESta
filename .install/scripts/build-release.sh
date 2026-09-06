#!/bin/sh
# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/scripts/build-release.sh
#
# Assembles a real, working offline installation bundle for one leaf
# installer's own seed package(s), proving offline installation is possible
# without any cryptographic signing: computes the seed package(s)' own full
# recursive dependency closure via 'apt-cache depends', then fetches every
# one of those packages plus the seed package(s) themselves as real .deb
# files directly into --output-dir via 'apt-get download' (never 'apt-get
# install --download-only', which silently skips any dependency already
# installed and already satisfying a requirement on this build host,
# producing a real, confirmed-via-CI incomplete bundle; 'apt-get download'
# has no such install-state awareness and always fetches the named package
# fresh). A bundle-manifest.json is written alongside the .deb files,
# recording each one's real sha256 (compute_sha256, from lib/checksum.sh --
# the exact same function and integrity model lib/agent.sh already uses for
# the vendored agent binary), name, version (read from the .deb itself via
# dpkg-deb -f, never parsed out of the filename by hand), a real "source"
# provenance string, and a real, honest, non-cryptographic "signature"
# label. This bundle is a leaf installer's own --offline-bundle input: that
# flag verifies every artifact's sha256 against this exact manifest before
# any dpkg -i mutation is attempted (see lib/offline-bundle.sh).
#
# Originally scoped to nginx alone (Phase 22); generalized (Phase 23) to any
# apt-based leaf installer's own seed package(s) via the required --package
# and --manifest flags, plus an optional --mariadb-repo flag that replicates
# mariadb/install.sh's own third-party repo registration on THIS build host
# before 'apt-get update' runs, so a MariaDB bundle can be built at all (its
# packages live outside Ubuntu's own default archive).
#
# This is a release-engineering build tool, not a leaf-service installer: it
# never claims to satisfy the --dry-run/--apply/--version installer contract
# shape (INSTALLER-CONTRACT.md), it is run once per release on a trusted,
# apt-connected host (or a CI runner, see Scenario 9 in
# .github/workflows/tests.yml), never against a tenant node, and it performs
# real, intentional mutation of its own: refreshing this build host's own
# apt package lists and, with --mariadb-repo, registering a third-party apt
# repository on this build host. Nothing on a tenant node is ever touched by
# this script.
set -eu

SCRIPT_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
INSTALL_ROOT=$(CDPATH='' cd -- "${SCRIPT_DIR}/.." && pwd)

# shellcheck source=../lib/json.sh
. "${INSTALL_ROOT}/lib/json.sh"
# shellcheck source=../lib/checksum.sh
. "${INSTALL_ROOT}/lib/checksum.sh"
# shellcheck source=../lib/preflight.sh
. "${INSTALL_ROOT}/lib/preflight.sh"

BUNDLE_MANIFEST_FILENAME="bundle-manifest.json"

# MariaDB Foundation repo registration constants. Must stay in lockstep with
# mariadb/install.sh's own identical constants and codename mapping (see
# mariadb_repo_codename below) -- these two files are the only two places
# this repo-registration sequence may ever appear.
MARIADB_KEYRING="/etc/apt/trusted.gpg.d/mariadb-keyring-2019.gpg"
MARIADB_SOURCES_LIST="/etc/apt/sources.list.d/mariadb.list"
MARIADB_PINNED_SERIES="11.4"

UBUNTU_RELEASE=""
OUTPUT_DIR=""
MANIFEST_PATH=""
PACKAGE_NAMES=""
BUNDLE_NAME=""
MARIADB_REPO=0

usage() {
    cat <<'USAGE' >&2
Usage: build-release.sh --ubuntu-release <version> --output-dir <path>
                         --manifest <path> --package "<name> [<name> ...]"
                         [--bundle-name <label>] [--mariadb-repo] [--help]

  --ubuntu-release <version>  Required. Must be one of --manifest's own
                               supported_ubuntu[] entries (e.g. 24.04).
                               Builds a bundle for exactly one release per
                               invocation.
  --output-dir <path>         Required. Directory to write the vendored
                               .deb files and bundle-manifest.json into;
                               created if it does not already exist.
  --manifest <path>           Required. Path to the leaf service's own
                               committed manifest.json (e.g.
                               .install/services/bind9/manifest.json),
                               governing the --ubuntu-release validation.
  --package "<name> ...">     Required. One or more apt package names,
                               space-separated inside a single quoted
                               argument, to seed the dependency closure and
                               download (e.g. --package "bind9 bind9-utils"
                               or --package "mariadb-server mariadb-client").
                               A single-package service just passes one name
                               (e.g. --package nginx).
  --bundle-name <label>       Optional. Human-readable label recorded in
                               bundle-manifest.json's own "bundle_for" field.
                               Defaults to --package's own value verbatim.
  --mariadb-repo               Optional. Before 'apt-get update' runs,
                               registers MariaDB Foundation's own apt
                               repository on THIS build host (GPG keyring,
                               sources.list.d entry, pinned to the
                               11.4 series), mirroring mariadb/install.sh's
                               own exact repo-registration sequence. Required
                               when --package names mariadb-server/
                               mariadb-client, since those packages live
                               outside Ubuntu's own default archive. Fails
                               closed if this build host's Ubuntu VERSION_ID
                               has no verified MariaDB 11.4 repository
                               codename mapping (mirroring
                               mariadb/install.sh's own disclosed 26.04 gap).
  --help                       Print this message and exit.

Downloads --package's own named package(s) and their full recursive
dependency closure (computed via apt-cache depends) as real .deb files
directly into --output-dir via 'apt-get download' (never 'apt-get install
--download-only', which silently skips a dependency already installed and
already satisfying a requirement on this build host), and writes
bundle-manifest.json recording each one's real name, version (read via
dpkg-deb -f), sha256, source, and signature -- consumed by a leaf
installer's own --offline-bundle flag (e.g. 'nginx/install.sh --apply --yes
--web-server nginx --offline-bundle <path>') for a fully offline install
requiring no network access at all. Must be run as root, on a real
Debian/Ubuntu host with real apt access.
USAGE
}

fail() {
    printf 'build-release.sh: %s\n' "$1" >&2
    exit 1
}

parse_args() {
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --ubuntu-release)
                [ "$#" -ge 2 ] || fail "--ubuntu-release requires a value"
                UBUNTU_RELEASE="$2"
                shift 2
                ;;
            --ubuntu-release=*)
                UBUNTU_RELEASE="${1#--ubuntu-release=}"
                shift
                ;;
            --output-dir)
                [ "$#" -ge 2 ] || fail "--output-dir requires a value"
                OUTPUT_DIR="$2"
                shift 2
                ;;
            --output-dir=*)
                OUTPUT_DIR="${1#--output-dir=}"
                shift
                ;;
            --manifest)
                [ "$#" -ge 2 ] || fail "--manifest requires a value"
                MANIFEST_PATH="$2"
                shift 2
                ;;
            --manifest=*)
                MANIFEST_PATH="${1#--manifest=}"
                shift
                ;;
            --package)
                [ "$#" -ge 2 ] || fail "--package requires a value"
                PACKAGE_NAMES="$2"
                shift 2
                ;;
            --package=*)
                PACKAGE_NAMES="${1#--package=}"
                shift
                ;;
            --bundle-name)
                [ "$#" -ge 2 ] || fail "--bundle-name requires a value"
                BUNDLE_NAME="$2"
                shift 2
                ;;
            --bundle-name=*)
                BUNDLE_NAME="${1#--bundle-name=}"
                shift
                ;;
            --mariadb-repo)
                MARIADB_REPO=1
                shift
                ;;
            --help)
                usage
                exit 0
                ;;
            *)
                usage
                fail "unrecognized argument: $1"
                ;;
        esac
    done

    if [ -z "${UBUNTU_RELEASE}" ]; then
        usage
        fail "--ubuntu-release is required"
    fi

    if [ -z "${OUTPUT_DIR}" ]; then
        usage
        fail "--output-dir is required"
    fi

    if [ -z "${MANIFEST_PATH}" ]; then
        usage
        fail "--manifest is required"
    fi

    if [ -z "${PACKAGE_NAMES}" ]; then
        usage
        fail "--package is required"
    fi

    BUNDLE_NAME="${BUNDLE_NAME:-${PACKAGE_NAMES}}"
}

# validate_ubuntu_release confirms UBUNTU_RELEASE is one of --manifest's own
# committed manifest.json's supported_ubuntu[] entries, sourced from the
# real file rather than duplicated as a literal here (manifest_extract_array
# comes from lib/preflight.sh, shared with every leaf installer's own
# equivalent check).
validate_ubuntu_release() {
    local supported

    [ -f "${MANIFEST_PATH}" ] || fail "--manifest ${MANIFEST_PATH} not found"

    supported=$(manifest_extract_array "${MANIFEST_PATH}" "supported_ubuntu")

    if ! printf '%s\n' "${supported}" | grep -Fxq "${UBUNTU_RELEASE}"; then
        fail "--ubuntu-release ${UBUNTU_RELEASE} is not in ${MANIFEST_PATH}'s own supported_ubuntu[] (supported: $(printf '%s' "${supported}" | tr '\n' ' '))"
    fi
}

# mariadb_repo_codename <version_id> -> prints the MariaDB Foundation apt
# repository's own codename for this Ubuntu VERSION_ID, or fails closed.
# Must stay in lockstep with mariadb/install.sh's own
# preflight_check_mariadb_repo_available: same mapping, same disclosed
# 26.04 gap (MariaDB Foundation's 11.4 repository currently publishes noble
# and jammy only), duplicated here rather than sourced since this script
# never sources a leaf installer file.
mariadb_repo_codename() {
    local version_id="$1"

    case "${version_id}" in
        24.04)
            printf 'noble'
            return 0
            ;;
        26.04)
            fail "MariaDB Foundation's apt repository for MariaDB ${MARIADB_PINNED_SERIES} LTS does not currently publish an Ubuntu 26.04 (resolute) suite -- it currently publishes noble (24.04) and jammy (22.04) only (see mariadb/install.sh's own top comment for the sources). Refusing to build a --mariadb-repo bundle on this build host's OS release rather than substitute an unapproved version."
            ;;
        *)
            fail "detected Ubuntu VERSION_ID=${version_id} on this build host, which has no verified MariaDB Foundation repository codename mapping"
            ;;
    esac
}

# register_mariadb_repo replicates mariadb/install.sh's own exact
# repo-registration sequence (ensure_mariadb_package_installed) on THIS
# build host: same GPG keyring URL/path, same sources.list.d content, same
# pinned 11.4 series. Only ever called when --mariadb-repo is given, before
# 'apt-get update' runs.
register_mariadb_repo() {
    local os_version_id codename out

    os_version_id=$(preflight_os_release_field VERSION_ID)
    codename=$(mariadb_repo_codename "${os_version_id}")

    printf 'build-release.sh: registering MariaDB Foundation apt repository (codename %s, series %s) on this build host\n' "${codename}" "${MARIADB_PINNED_SERIES}" >&2

    if ! out=$(curl -fsSL -o "${MARIADB_KEYRING}" "https://supplychain.mariadb.com/mariadb-keyring-2019.gpg" 2>&1); then
        fail "failed to fetch MariaDB Foundation's release signing key: ${out}"
    fi
    chmod 0644 "${MARIADB_KEYRING}"

    cat > "${MARIADB_SOURCES_LIST}.tmp" <<SOURCES
# MariaDB ${MARIADB_PINNED_SERIES} LTS repository (MariaDB Foundation), pinned to the
# ${MARIADB_PINNED_SERIES} major series so it tracks that series' own minor updates.
# Written by build-release.sh --mariadb-repo; mirrors mariadb/install.sh's
# own identical repo registration.
deb [arch=amd64 signed-by=${MARIADB_KEYRING}] https://deb.mariadb.org/${MARIADB_PINNED_SERIES}/ubuntu ${codename} main
SOURCES
    mv -f "${MARIADB_SOURCES_LIST}.tmp" "${MARIADB_SOURCES_LIST}"

    printf 'build-release.sh: MariaDB Foundation apt repository registered at %s\n' "${MARIADB_SOURCES_LIST}" >&2
}

main() {
    local artifact_lines="" deb_path deb_name deb_version deb_sha256 artifact_json copied_count=0
    local artifacts_json bundle_manifest_json package_deps

    parse_args "$@"
    validate_ubuntu_release

    if [ "$(id -u)" -ne 0 ]; then
        fail "must run as root: 'apt-get update' needs write access to /var/lib/apt/lists"
    fi

    command -v apt-get >/dev/null 2>&1 || fail "apt-get not found; this script must run on a real Debian/Ubuntu host with apt access"
    command -v dpkg-deb >/dev/null 2>&1 || fail "dpkg-deb not found"

    install -d -m 0755 "${OUTPUT_DIR}" || fail "failed to create ${OUTPUT_DIR}"

    if [ "${MARIADB_REPO}" -eq 1 ]; then
        register_mariadb_repo
    fi

    printf 'build-release.sh: refreshing apt package lists for ubuntu %s\n' "${UBUNTU_RELEASE}" >&2
    apt-get update

    # 'apt-get install --reinstall --download-only' only reliably fetches
    # packages apt actually needs to CHANGE: a dependency that is already
    # installed and already satisfies a requirement is silently skipped,
    # since apt sees nothing to do for it, even under --download-only. On a
    # build host that already has some of these dependencies present (a real
    # possibility, not just a CI artifact of re-running this script after a
    # prior scenario already installed the package), this would silently
    # produce an incomplete bundle missing exactly those already-satisfied
    # .deb files, confirmed for real via a CI failure where nginx-common was
    # missing from the bundle for precisely this reason. 'apt-get download'
    # has no such install-state awareness at all: it always fetches the
    # named package's own .deb fresh, regardless of whether it is already
    # installed, so computing the full recursive dependency closure
    # ourselves and downloading every member of it by name is the only
    # reliable way to guarantee a complete, build-host-state-independent
    # bundle.
    printf 'build-release.sh: computing %s'"'"'s full recursive dependency closure\n' "${PACKAGE_NAMES}" >&2
    # shellcheck disable=SC2086
    package_deps=$(apt-cache depends --recurse --no-recommends --no-suggests --no-conflicts --no-breaks --no-replaces --no-enhances ${PACKAGE_NAMES} | grep -E '^[[:alnum:]]' | sort -u)
    [ -n "${package_deps}" ] || fail "apt-cache depends returned no dependency closure for ${PACKAGE_NAMES}; refusing to build an incomplete bundle"

    printf 'build-release.sh: downloading %s and its full dependency closure into %s (apt-get download: independent of this host'"'"'s own current install state)\n' "${PACKAGE_NAMES}" "${OUTPUT_DIR}" >&2
    # shellcheck disable=SC2086
    (cd "${OUTPUT_DIR}" && apt-get download ${PACKAGE_NAMES} ${package_deps}) || fail "apt-get download failed for ${PACKAGE_NAMES} and/or one of its dependencies"

    for deb_path in "${OUTPUT_DIR}"/*.deb; do
        [ -e "${deb_path}" ] || continue

        deb_name=$(basename "${deb_path}")

        deb_version=$(dpkg-deb -f "${deb_path}" Version) || fail "dpkg-deb could not read the Version field from ${deb_name}"
        deb_sha256=$(compute_sha256 "${OUTPUT_DIR}/${deb_name}")

        artifact_json=$(json_join_object \
            "$(json_kv_str "name" "${deb_name}")" \
            "$(json_kv_str "version" "${deb_version}")" \
            "$(json_kv_str "source" "vendored:apt-download")" \
            "$(json_kv_str "sha256" "${deb_sha256}")" \
            "$(json_kv_str "signature" "checksum-only:release-bundle")")
        artifact_lines=$(append_line "${artifact_lines}" "${artifact_json}")
        copied_count=$((copied_count + 1))

        printf 'build-release.sh: vendored %s (version %s, sha256 %s)\n' "${deb_name}" "${deb_version}" "${deb_sha256}" >&2
    done

    [ "${copied_count}" -gt 0 ] || fail "no .deb files found in ${OUTPUT_DIR} after apt-get download; nothing to bundle"

    artifacts_json=$(json_array_from_lines "${artifact_lines}")
    bundle_manifest_json=$(json_join_object \
        "$(json_kv_str "schema_version" "1")" \
        "$(json_kv_str "bundle_for" "${BUNDLE_NAME}")" \
        "$(json_kv_str "ubuntu_release" "${UBUNTU_RELEASE}")" \
        "$(json_kv_raw "artifacts" "${artifacts_json}")")

    printf '%s\n' "${bundle_manifest_json}" > "${OUTPUT_DIR}/${BUNDLE_MANIFEST_FILENAME}" || fail "failed to write ${OUTPUT_DIR}/${BUNDLE_MANIFEST_FILENAME}"

    printf 'build-release.sh: wrote %s with %s vendored .deb artifact(s)\n' "${OUTPUT_DIR}/${BUNDLE_MANIFEST_FILENAME}" "${copied_count}" >&2
}

main "$@"
