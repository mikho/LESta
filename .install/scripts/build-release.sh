#!/bin/sh
# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/scripts/build-release.sh
#
# Assembles a real, working offline installation bundle for the nginx leaf
# installer, scoped to nginx only (the simplest single-package installer),
# proving offline installation is possible without any cryptographic
# signing: 'apt-get install --reinstall --download-only' fetches nginx and
# its full dependency closure as .deb files into /var/cache/apt/archives
# without installing or mutating anything else on this build host. Every
# downloaded .deb is copied into --output-dir alongside a bundle-manifest.json
# recording its real sha256 (compute_sha256, from lib/checksum.sh -- the
# exact same function and integrity model lib/agent.sh already uses for the
# vendored agent binary), name, version (read from the .deb itself via
# dpkg-deb -f, never parsed out of the filename by hand), a real "source"
# provenance string, and a real, honest, non-cryptographic "signature"
# label. This bundle is nginx/install.sh's own --offline-bundle input: that
# flag verifies every artifact's sha256 against this exact manifest before
# any dpkg -i mutation is attempted.
#
# This is a release-engineering build tool, not a leaf-service installer: it
# never claims to satisfy the --dry-run/--apply/--version installer contract
# shape (INSTALLER-CONTRACT.md), it is run once per release on a trusted,
# apt-connected host (or a CI runner, see Scenario 9 in
# .github/workflows/tests.yml), never against a tenant node, and it performs
# one real, intentional mutation of its own: refreshing and populating this
# build host's own local apt package cache. Nothing on a tenant node is ever
# touched by this script.
set -eu

SCRIPT_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
INSTALL_ROOT=$(CDPATH='' cd -- "${SCRIPT_DIR}/.." && pwd)

# shellcheck source=../lib/json.sh
. "${INSTALL_ROOT}/lib/json.sh"
# shellcheck source=../lib/checksum.sh
. "${INSTALL_ROOT}/lib/checksum.sh"
# shellcheck source=../lib/preflight.sh
. "${INSTALL_ROOT}/lib/preflight.sh"

NGINX_MANIFEST="${INSTALL_ROOT}/services/nginx/manifest.json"
BUNDLE_MANIFEST_FILENAME="bundle-manifest.json"

UBUNTU_RELEASE=""
OUTPUT_DIR=""

usage() {
    cat <<'USAGE' >&2
Usage: build-release.sh --ubuntu-release <version> --output-dir <path> [--help]

  --ubuntu-release <version>  Required. Must be one of nginx/manifest.json's
                               own supported_ubuntu[] entries (e.g. 24.04).
                               Builds a bundle for exactly one release per
                               invocation.
  --output-dir <path>         Required. Directory to write the vendored
                               .deb files and bundle-manifest.json into;
                               created if it does not already exist.
  --help                      Print this message and exit.

Downloads nginx and its full dependency closure as .deb files via
'apt-get install --reinstall --download-only -y nginx' (fetches into this
host's own apt cache; installs or mutates nothing else), copies every
resulting .deb into --output-dir, and writes bundle-manifest.json recording
each one's real name, version (read via dpkg-deb -f), sha256, source, and
signature -- consumed by:

  nginx/install.sh --apply --yes --web-server nginx --offline-bundle <path>

for a fully offline install requiring no network access at all. Must be run
as root, on a real Debian/Ubuntu host with real apt access.
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
}

# validate_ubuntu_release confirms UBUNTU_RELEASE is one of nginx's own
# committed manifest.json's supported_ubuntu[] entries, sourced from the
# real file rather than duplicated as a literal here (manifest_extract_array
# comes from lib/preflight.sh, shared with every leaf installer's own
# equivalent check).
validate_ubuntu_release() {
    local supported

    supported=$(manifest_extract_array "${NGINX_MANIFEST}" "supported_ubuntu")

    if ! printf '%s\n' "${supported}" | grep -Fxq "${UBUNTU_RELEASE}"; then
        fail "--ubuntu-release ${UBUNTU_RELEASE} is not in ${NGINX_MANIFEST}'s own supported_ubuntu[] (supported: $(printf '%s' "${supported}" | tr '\n' ' '))"
    fi
}

main() {
    local artifact_lines="" deb_path deb_name deb_version deb_sha256 artifact_json copied_count=0
    local artifacts_json bundle_manifest_json

    parse_args "$@"
    validate_ubuntu_release

    if [ "$(id -u)" -ne 0 ]; then
        fail "must run as root: 'apt-get install --download-only' needs write access to /var/cache/apt/archives"
    fi

    command -v apt-get >/dev/null 2>&1 || fail "apt-get not found; this script must run on a real Debian/Ubuntu host with apt access"
    command -v dpkg-deb >/dev/null 2>&1 || fail "dpkg-deb not found"

    install -d -m 0755 "${OUTPUT_DIR}" || fail "failed to create ${OUTPUT_DIR}"

    printf 'build-release.sh: refreshing apt package lists for ubuntu %s\n' "${UBUNTU_RELEASE}" >&2
    apt-get update

    printf 'build-release.sh: downloading nginx and its full dependency closure (download-only: fetches into /var/cache/apt/archives, installs nothing)\n' >&2
    apt-get install --reinstall --download-only -y nginx

    for deb_path in /var/cache/apt/archives/*.deb; do
        [ -e "${deb_path}" ] || continue

        deb_name=$(basename "${deb_path}")
        cp "${deb_path}" "${OUTPUT_DIR}/${deb_name}" || fail "failed to copy ${deb_path} into ${OUTPUT_DIR}"

        deb_version=$(dpkg-deb -f "${OUTPUT_DIR}/${deb_name}" Version) || fail "dpkg-deb could not read the Version field from ${deb_name}"
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

    [ "${copied_count}" -gt 0 ] || fail "no .deb files found in /var/cache/apt/archives after the download-only install; nothing to bundle"

    artifacts_json=$(json_array_from_lines "${artifact_lines}")
    bundle_manifest_json=$(json_join_object \
        "$(json_kv_str "schema_version" "1")" \
        "$(json_kv_str "bundle_for" "nginx")" \
        "$(json_kv_str "ubuntu_release" "${UBUNTU_RELEASE}")" \
        "$(json_kv_raw "artifacts" "${artifacts_json}")")

    printf '%s\n' "${bundle_manifest_json}" > "${OUTPUT_DIR}/${BUNDLE_MANIFEST_FILENAME}" || fail "failed to write ${OUTPUT_DIR}/${BUNDLE_MANIFEST_FILENAME}"

    printf 'build-release.sh: wrote %s with %s vendored .deb artifact(s)\n' "${OUTPUT_DIR}/${BUNDLE_MANIFEST_FILENAME}" "${copied_count}" >&2
}

main "$@"
