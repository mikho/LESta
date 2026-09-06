# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/lib/offline-bundle.sh
#
# The --offline-bundle mechanism every leaf-service installer's own
# --apply/--dry-run wiring shares: a bundle directory produced by
# .install/scripts/build-release.sh (one or more vendored .deb files plus a
# bundle-manifest.json recording each one's name/version/sha256) is
# structurally checked at preflight time, then fully sha256-verified
# immediately before any dpkg -i mutation is attempted. Relocated verbatim
# from nginx/install.sh (this project's first --offline-bundle
# implementation, Phase 22): every function here was already fully generic,
# containing no nginx-specific string or logic anywhere in its body, so
# nginx/install.sh now sources this file instead of defining its own copies,
# keeping only its own thin install_nginx_offline_bundle wrapper local.
#
# Depends on json.sh, checksum.sh (compute_sha256/verify_sha256), result.sh
# (add_error/fail_step), preflight.sh (manifest_artifact_sha256), and run.sh's
# EXIT_* constants, all already sourced by any installer that sources this
# file.

# BUNDLE_MANIFEST_FILENAME must stay in lockstep with
# .install/scripts/build-release.sh's own BUNDLE_MANIFEST_FILENAME: this is
# the one, freshly-generated-at-build-time file name both sides agree on to
# find a --offline-bundle directory's own artifacts[] manifest. It is never a
# committed source manifest (unlike a service's own manifest.json) and never
# touched by .install/scripts/validate-contract.mjs, which only discovers
# services/*/manifest.json.
BUNDLE_MANIFEST_FILENAME="bundle-manifest.json"

# preflight_check_offline_bundle_present <bundle_dir>
# Structural-only check (directory and manifest file exist), reported at
# preflight time for both --dry-run and --apply per
# INSTALLER-CONTRACT.md's own "package source reachability or offline
# bundle completeness" preflight requirement. Deliberately does NOT verify
# any sha256 here: that full checksum verification (the fail-closed-before-
# any-mutation guarantee) happens once, immediately before the caller's own
# install_<service>_offline_bundle's own dpkg -i, via
# verify_offline_bundle_artifacts below -- duplicating it here would only let
# a bundle that fails preflight but is fixed a moment later go unverified a
# second time before use.
preflight_check_offline_bundle_present() {
    local bundle_dir="$1"
    local manifest="${bundle_dir}/${BUNDLE_MANIFEST_FILENAME}"

    if [ ! -d "${bundle_dir}" ]; then
        add_error artifact_missing "offline bundle directory not found: ${bundle_dir}" "${bundle_dir}"
        return 1
    fi

    if [ ! -f "${manifest}" ]; then
        add_error artifact_missing "offline bundle manifest not found: ${manifest} (run .install/scripts/build-release.sh first)" "${manifest}"
        return 1
    fi

    return 0
}

# offline_bundle_artifact_names <bundle_manifest_file> -> one .deb filename
# per line, read from the bundle's own artifacts[].name field. Mirrors
# lib/preflight.sh's own manifest_artifact_sha256 parsing approach exactly
# (artifacts[] declared on a single line, no nested braces inside an entry
# -- guaranteed here since .install/scripts/build-release.sh writes this
# file with lib/json.sh's own compact json_join_object/json_array_from_lines
# helpers, never hand-formatted).
offline_bundle_artifact_names() {
    local file="$1" line

    line=$(grep -o '"artifacts"[[:space:]]*:[[:space:]]*\[.*\]' "${file}" || true)
    [ -n "${line}" ] || return 0

    printf '%s' "${line}" \
        | grep -o '"name"[[:space:]]*:[[:space:]]*"[^"]*"' \
        | sed -n 's/.*"name"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p'
}

# verify_offline_bundle_artifacts <bundle_dir>
# The offline counterpart of lib/agent.sh's own agent_install_binary
# checksum discipline, generalized from one vendored Go binary to a set of
# vendored .deb files: every artifact bundle-manifest.json declares must
# exist at <bundle_dir>/<name> and its real sha256 (compute_sha256/
# verify_sha256, from lib/checksum.sh) must match the manifest's own
# recorded value. Fails closed via fail_step (EXIT_VERIFICATION_FAILURE)
# on the FIRST missing file or mismatch, before the caller's own
# install_<service>_offline_bundle ever runs dpkg -i against anything -- no
# partial, partially-verified install is ever attempted.
verify_offline_bundle_artifacts() {
    local bundle_dir="$1"
    local bundle_manifest="${bundle_dir}/${BUNDLE_MANIFEST_FILENAME}"
    local names name deb_path expected_sha256 found=0

    [ -f "${bundle_manifest}" ] || fail_step "${EXIT_VERIFICATION_FAILURE}" artifact_missing "${bundle_manifest}" "offline bundle manifest not found; run .install/scripts/build-release.sh first"

    names=$(offline_bundle_artifact_names "${bundle_manifest}")
    [ -n "${names}" ] || fail_step "${EXIT_VERIFICATION_FAILURE}" artifact_not_declared "${bundle_manifest}" "offline bundle manifest has no artifacts[] entries"

    while IFS= read -r name; do
        [ -n "${name}" ] || continue
        found=1
        deb_path="${bundle_dir}/${name}"

        expected_sha256=$(manifest_artifact_sha256 "${bundle_manifest}" "${name}")
        [ -n "${expected_sha256}" ] || fail_step "${EXIT_VERIFICATION_FAILURE}" artifact_not_declared "${bundle_manifest}" "no sha256 recorded for ${name} in ${bundle_manifest}"

        [ -f "${deb_path}" ] || fail_step "${EXIT_VERIFICATION_FAILURE}" artifact_missing "${deb_path}" "offline bundle artifact ${name} is declared in ${bundle_manifest} but not found at ${deb_path}"

        verify_sha256 "${deb_path}" "${expected_sha256}" \
            || fail_step "${EXIT_VERIFICATION_FAILURE}" checksum_mismatch "${deb_path}" "offline bundle artifact ${name} sha256 does not match ${bundle_manifest}'s artifacts[] entry; refusing to install any package from a tampered offline bundle"
    done <<NAMES
${names}
NAMES

    [ "${found}" -eq 1 ] || fail_step "${EXIT_VERIFICATION_FAILURE}" artifact_not_declared "${bundle_manifest}" "offline bundle manifest artifacts[] parsed as empty"
}

# install_offline_bundle_debs <bundle_dir>
# Installs every vendored .deb in <bundle_dir> via 'dpkg -i', requiring no
# network access at all. Runs dpkg -i up to twice: 'dpkg -i <dir>/*.deb'
# relies on the shell glob's own alphabetical ordering, which does not
# always respect a package's own Pre-Depends relationships -- confirmed for
# real via a CI failure where mariadb-server (which Pre-Depends on
# mysql-common, sorting alphabetically AFTER it) failed to unpack with
# "pre-dependency problem" on the first pass, before mysql-common had been
# configured yet. A second pass, run only if the first one fails, retries
# with every order-independent package (including mysql-common) already
# configured by the first pass, which is the standard, purely-offline
# technique for this -- apt's own solver never hits this on the live path,
# since it always computes a Pre-Depends-respecting install order itself.
# On stdout: the failed pass's own captured output, if both passes failed
# (empty on success). Returns 0 on success (from either pass), 1 if both
# failed.
install_offline_bundle_debs() {
    local bundle_dir="$1" out

    if out=$(dpkg -i "${bundle_dir}"/*.deb 2>&1); then
        return 0
    fi

    if out=$(dpkg -i "${bundle_dir}"/*.deb 2>&1); then
        return 0
    fi

    printf '%s' "${out}"
    return 1
}
