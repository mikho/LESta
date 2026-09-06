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

# manifest_artifact_version <bundle_manifest_file> <seed_package_name> -> the
# version string recorded for that package's artifact in artifacts[], or
# empty if not found. Mirrors lib/preflight.sh's own manifest_artifact_sha256
# parsing shape exactly (artifacts[] declared on a single line, per-entry
# brace match via grep -o "{[^}]*...[^}]*}", sed to pull one field out) but
# reads the already-recorded "version" field instead of "sha256". One
# deliberate adaptation from a literal mirror: manifest_artifact_sha256's own
# <artifact-name> parameter matches an artifacts[].name value EXACTLY, which
# is correct there because its caller always already has the exact .deb
# filename in hand (read from this same manifest's own name field a moment
# earlier). Here the two manifests being compared (an old generation's and a
# new one's) almost always name their .deb file differently, since
# build-release.sh's own "name" field is the full .deb filename, which
# embeds the package version being looked up in the first place
# (e.g. "nginx_1.24.0-2ubuntu7.4_amd64.deb") -- an exact match would never
# succeed across two different versions. So seed_package_name is instead
# matched as a name-field PREFIX ("<seed_package_name>_"), the same
# "<name>_<version>_<arch>.deb" convention apt itself uses, which is stable
# across versions.
manifest_artifact_version() {
    local file="$1" name="$2" line

    line=$(grep -o '"artifacts"[[:space:]]*:[[:space:]]*\[.*\]' "${file}" 2>/dev/null || true)
    [ -n "${line}" ] || return 0

    printf '%s' "${line}" \
        | grep -o "{[^}]*\"name\"[[:space:]]*:[[:space:]]*\"${name}_[^}]*}" \
        | sed -n 's/.*"version"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' \
        | head -n1
}

# offline_bundle_retain_generation <service> <bundle_dir> <seed_artifact_name>
# The offline counterpart of lib/agent.sh's own agent_install_binary
# generation-swap bookkeeping, generalized from one vendored binary to a
# whole vendored .deb bundle. service is one of nginx/bind9/apache/mariadb
# and is used ONLY to build the retention path below -- this function has no
# other service-specific logic. seed_artifact_name is the one package name
# (matched as a manifest_artifact_version name-field prefix, see its own
# comment above) whose version is treated as the whole bundle's own
# generation identity; nginx's own caller resolves this to either "nginx" or
# "nginx-core" first (see nginx_offline_bundle_seed_artifact_name), mirroring
# nginx_package_provenance_note's own either/or technique for the same
# ambiguity. Retention layout, fixed for every service:
#   /var/lib/lesta/<service>/bundle-generations/current/   -- the generation
#     currently installed (or about to be, see the split below).
#   /var/lib/lesta/<service>/bundle-generations/previous/  -- the generation
#     this one replaced, kept only across a real version change, restored by
#     offline_bundle_rollback_generation below.
#
# Split of responsibility with offline_bundle_snapshot_current below: THIS
# function only ever does swap bookkeeping (deciding whether a version
# change is happening at all, and if so, retiring the old current/ to
# previous/ first) -- it never itself writes the new bundle into current/.
# That final "make current/ reflect the bundle just verified and installed"
# step is always offline_bundle_snapshot_current's own job instead, run by
# every installer's own wrapper AFTER install_offline_bundle_debs succeeds,
# on both the fresh-install and the generation-swap path alike. This keeps
# the four installers' own wrappers structurally identical (retain, verify,
# install, snapshot) rather than branching differently depending on which
# case they are in.
#
#   - current/bundle-manifest.json does not exist yet: a genuinely fresh
#     install. The retention root is created (no previous/ -- there is
#     nothing yet to retain), and this returns 0 immediately; the caller's
#     own offline_bundle_snapshot_current call afterward populates current/
#     for the first time.
#   - current/bundle-manifest.json exists and its seed_artifact_name version
#     matches the new bundle's own: a true no-op for the swap itself (no
#     previous/ is touched); the caller's own offline_bundle_snapshot_current
#     call afterward still re-copies the (unchanged) bundle into current/,
#     which is harmless idempotent reconvergence, not a real swap.
#   - current/bundle-manifest.json exists and the version differs: a real
#     generation change. Any prior previous/ is removed first, then the
#     current live current/ is atomically retired to previous/ using the
#     same write-to-tmp-then-mv discipline agent_install_binary uses (here
#     at the directory level: cp -a into a tmp directory under the SAME
#     retention root -- guaranteed same filesystem as the final destination
#     -- then mv -f the tmp directory into place), BEFORE the caller's own
#     verify_offline_bundle_artifacts/install_offline_bundle_debs ever run
#     against the new bundle, so a verify failure on the new bundle right
#     after this call still leaves a good previous/ in place. current/ is
#     then removed outright (not merely left stale) so the caller's own
#     snapshot step always starts from a clean directory.
#
# Fails closed via fail_step (EXIT_MUTATION_FAILURE) on any real I/O error;
# never leaves current/previous half-written on a cp/mv failure. Returns 0
# on every other path.
offline_bundle_retain_generation() {
    local service="$1" bundle_dir="$2" seed_artifact_name="$3"
    local retain_root="/var/lib/lesta/${service}/bundle-generations"
    local current_dir="${retain_root}/current"
    local previous_dir="${retain_root}/previous"
    local current_manifest="${current_dir}/${BUNDLE_MANIFEST_FILENAME}"
    local new_manifest="${bundle_dir}/${BUNDLE_MANIFEST_FILENAME}"
    local current_version new_version tmp_dir

    install -d -m 0750 -o root -g lesta "${retain_root}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${retain_root}" "failed to create ${retain_root}"

    if [ ! -f "${current_manifest}" ]; then
        log_info "offline_bundle_retain_generation: no ${current_manifest} yet; fresh ${service} offline-bundle install, nothing to retain or swap"
        return 0
    fi

    current_version=$(manifest_artifact_version "${current_manifest}" "${seed_artifact_name}")
    new_version=$(manifest_artifact_version "${new_manifest}" "${seed_artifact_name}")

    if [ "${current_version}" = "${new_version}" ]; then
        log_info "offline_bundle_retain_generation: ${service} ${seed_artifact_name} version unchanged (${new_version:-unknown}); no generation swap"
        return 0
    fi

    log_info "offline_bundle_retain_generation: ${service} ${seed_artifact_name} version changing (${current_version:-unknown} -> ${new_version:-unknown}); retiring the current generation to ${previous_dir}"

    rm -rf "${previous_dir}"

    tmp_dir="${retain_root}/.previous.tmp"
    rm -rf "${tmp_dir}"
    cp -a "${current_dir}" "${tmp_dir}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" backup_failed "${previous_dir}" "failed to copy ${current_dir} to ${tmp_dir} before retiring it to ${previous_dir}"
    mv -f "${tmp_dir}" "${previous_dir}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" backup_failed "${previous_dir}" "failed to rename ${tmp_dir} into place as ${previous_dir}"

    rm -rf "${current_dir}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" backup_failed "${current_dir}" "failed to clear ${current_dir} after retiring it to ${previous_dir}"

    return 0
}

# offline_bundle_snapshot_current <service> <bundle_dir>
# The other half of the retention split offline_bundle_retain_generation
# documents above: makes /var/lib/lesta/<service>/bundle-generations/current/
# an exact copy of bundle_dir, using the same cp-into-tmp-then-mv-into-place
# discipline as agent_install_binary, at the directory level. Always called
# by every installer's own install_<service>_offline_bundle wrapper
# immediately after install_offline_bundle_debs succeeds -- on both the
# fresh-install and the generation-swap path alike (see
# offline_bundle_retain_generation's own comment for why the split falls
# here). Fails closed via fail_step (EXIT_MUTATION_FAILURE) on any I/O error.
offline_bundle_snapshot_current() {
    local service="$1" bundle_dir="$2"
    local retain_root="/var/lib/lesta/${service}/bundle-generations"
    local current_dir="${retain_root}/current"
    local tmp_dir="${retain_root}/.current.tmp"

    install -d -m 0750 -o root -g lesta "${retain_root}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${retain_root}" "failed to create ${retain_root}"

    rm -rf "${tmp_dir}"
    cp -a "${bundle_dir}" "${tmp_dir}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" backup_failed "${current_dir}" "failed to copy ${bundle_dir} to ${tmp_dir} before snapshotting it as the current retained generation"
    rm -rf "${current_dir}"
    mv -f "${tmp_dir}" "${current_dir}" \
        || fail_step "${EXIT_MUTATION_FAILURE}" backup_failed "${current_dir}" "failed to rename ${tmp_dir} into place as ${current_dir}"
}

# offline_bundle_would_retain_note <service> <bundle_dir> <seed_artifact_name>
# Read-only --dry-run counterpart to offline_bundle_retain_generation: never
# creates, copies, or moves anything (no install -d, no cp/mv) -- it only
# ever reads the two manifests (if the current one even exists yet) to
# describe what a real --apply would do to the retention directories.
# Printed as a trailing clause each installer's own *_would_install_note
# appends when OFFLINE_BUNDLE is set.
offline_bundle_would_retain_note() {
    local service="$1" bundle_dir="$2" seed_artifact_name="$3"
    local current_manifest="/var/lib/lesta/${service}/bundle-generations/current/${BUNDLE_MANIFEST_FILENAME}"
    local new_manifest="${bundle_dir}/${BUNDLE_MANIFEST_FILENAME}"
    local current_version new_version

    if [ ! -f "${current_manifest}" ]; then
        printf '; this would be a fresh %s offline-bundle install, no prior generation is retained yet under /var/lib/lesta/%s/bundle-generations' "${service}" "${service}"
        return 0
    fi

    current_version=$(manifest_artifact_version "${current_manifest}" "${seed_artifact_name}")
    new_version=$(manifest_artifact_version "${new_manifest}" "${seed_artifact_name}")

    if [ "${current_version}" = "${new_version}" ]; then
        printf '; %s %s version is unchanged (%s), no generation swap would occur' "${service}" "${seed_artifact_name}" "${new_version:-unknown}"
    else
        printf '; a real generation change would occur (%s %s -> %s): the previous generation would be retained under /var/lib/lesta/%s/bundle-generations/previous/' "${seed_artifact_name}" "${current_version:-unknown}" "${new_version:-unknown}" "${service}"
    fi
}

# offline_bundle_rollback_generation <service> <systemd_units>
# Restores /var/lib/lesta/<service>/bundle-generations/previous back over the
# live package state, using the same atomic-restore intent as
# lib/agent.sh's own agent_rollback_binary, adapted to a set of .deb
# packages rather than one binary file: 'dpkg -i' is itself the atomic unit
# of restoration here (there is no tmp-then-mv for package state -- dpkg's
# own database is the thing being changed), retried once exactly like
# install_offline_bundle_debs already does for Pre-Depends ordering. The
# retry is duplicated inline here rather than delegated to
# install_offline_bundle_debs itself: that shared function's own dpkg -i
# invocation has no way to accept the extra --force-downgrade
# --force-confold flags a rollback needs without either changing its
# existing signature (risking the live, unrelated install path bundled
# through the same function) or growing an optional-flags parameter no
# other caller would ever use. A few duplicated lines here is the smaller,
# safer footprint.
#
# --force-confold (not --force-confnew): every piece of LESta's own managed
# state for this package lives entirely outside any package conffile (its
# own lesta.d / <service>-release fragments, own datadir, own separately
# managed AppArmor local-override file), so the downgrade's stock conffile
# content is never load-bearing for LESta's own behavior either way, while
# --force-confnew would risk silently discarding an operator's own hand
# edits to the live conffile for no benefit. The conservative, non-
# destructive choice is --force-confold.
#
# systemd_units is a space-separated list (mariadb's own caller passes
# "mariadb.service mariadb@tenant.service", since one shared mariadb-server
# package underlies both instances; the other three installers each pass
# their own single unit name). After a successful dpkg -i, this always runs
# `systemctl daemon-reload` once, then `systemctl restart` for EVERY unit in
# the list, mirroring each installer's own existing "always restart, never
# just enable --now" fix for the supplementary-group/process-exec-time
# ordering issue documented at each of their own call sites.
#
# Three return codes, mirroring agent_rollback_binary exactly:
#   0 - previous/ existed, the dpkg -i restore and every systemd unit
#       restart succeeded.
#   1 - previous/ does not exist: nothing to roll back to (an ordinary
#       failed fresh install, not a rollback scenario).
#   2 - previous/ existed but the restore itself failed (dpkg -i failed even
#       after retry, daemon-reload failed, or some unit's restart failed).
#       previous/ is deliberately left in place either way -- mirrors
#       agent_rollback_binary's own behavior of never deleting its own
#       backup after a restore attempt, successful or not, in case another
#       rollback attempt is needed later.
offline_bundle_rollback_generation() {
    local service="$1" systemd_units="$2" unit
    local retain_root="/var/lib/lesta/${service}/bundle-generations"
    local previous_dir="${retain_root}/previous"
    local out

    if [ ! -d "${previous_dir}" ]; then
        log_info "offline_bundle_rollback_generation: no ${previous_dir} to restore for ${service}"
        return 1
    fi

    if ! out=$(DEBIAN_FRONTEND=noninteractive dpkg -i --force-downgrade --force-confold "${previous_dir}"/*.deb 2>&1); then
        if ! out=$(DEBIAN_FRONTEND=noninteractive dpkg -i --force-downgrade --force-confold "${previous_dir}"/*.deb 2>&1); then
            log_error "offline_bundle_rollback_generation: dpkg -i --force-downgrade --force-confold ${previous_dir}/*.deb failed (twice) while rolling back ${service}: $(printf '%s' "${out}" | tr '\n' ' ')"
            return 2
        fi
    fi

    # current/ must be made to reflect previous/ again once the restore
    # above actually succeeds: the failed generation-swap attempt this
    # rollback is undoing may have already cleared current/ (see
    # offline_bundle_retain_generation's own "current/ is removed outright"
    # step, which runs before install_offline_bundle_debs -- including when
    # THAT is what failed and triggered this very rollback), so without
    # this, the next --apply's own offline_bundle_retain_generation would
    # wrongly see no current/ at all and treat it as a fresh install rather
    # than correctly comparing against the generation actually running now.
    offline_bundle_snapshot_current "${service}" "${previous_dir}"

    if ! out=$(systemctl daemon-reload 2>&1); then
        log_error "offline_bundle_rollback_generation: systemctl daemon-reload failed while rolling back ${service}: ${out}"
        return 2
    fi

    for unit in ${systemd_units}; do
        # reset-failed first, ignoring its own exit code (harmless no-op if
        # the unit was never in a failed state): the broken generation this
        # rollback is undoing may have already tried and failed to start
        # this very unit (a real package's own postinst often attempts a
        # start/restart during dpkg -i, see offline_bundle_fail_health_
        # with_rollback's own dpkg_install_failed callers), which can leave
        # systemd's own start-limit rate-throttling engaged for it --
        # confirmed for real via a CI failure where the immediately-
        # following systemctl restart failed here for exactly this reason.
        # Without clearing that first, a plain restart can fail even though
        # the binary being restarted this time is the good, restored one.
        systemctl reset-failed "${unit}" >/dev/null 2>&1 || true

        if ! out=$(systemctl restart "${unit}" 2>&1); then
            log_error "offline_bundle_rollback_generation: systemctl restart ${unit} failed while rolling back ${service}: ${out}"
            return 2
        fi
    done

    add_change offline-bundle.rollback.v1 rolled_back "${previous_dir}" "restored ${service}'s previous offline-bundle generation from ${previous_dir} via dpkg -i --force-downgrade --force-confold, then restarted: ${systemd_units}"
    log_info "offline_bundle_rollback_generation: restored ${previous_dir} for ${service}, restarted: ${systemd_units}"
    return 0
}

# offline_bundle_fail_health_with_rollback <exit_code> <error_code> <path> <message> <service> <systemd_units>
# Drop-in replacement for a plain fail_step call at the exact point an
# --offline-bundle installer's own package-level health check (config
# validation, systemctl enable/restart, or a real health probe) has just
# determined the freshly (re)installed OS package failed. Mirrors
# lib/agent.sh's own agent_fail_selftest_with_rollback exactly in shape:
#   - offline_bundle_rollback_generation returns 1 (nothing to restore, an
#     ordinary fresh-install failure): behaves exactly like a plain
#     fail_step with the same exit_code/error_code/path/message.
#   - returns 0 (restored): fails with the SAME exit_code the health
#     failure would have used before rollback support existed, message
#     extended to note the automatic rollback, so an operator reading the
#     output understands the new generation was rejected, not that
#     everything just failed unexplained.
#   - returns 2 (restore itself failed): escalates to EXIT_ROLLBACK_FAILURE,
#     noting both the original failure and that the rollback attempt itself
#     also failed, needing manual operator intervention.
offline_bundle_fail_health_with_rollback() {
    local exit_code="$1" error_code="$2" path="$3" message="$4" service="$5" systemd_units="$6" rollback_status=0

    offline_bundle_rollback_generation "${service}" "${systemd_units}" || rollback_status=$?

    case "${rollback_status}" in
        1)
            fail_step "${exit_code}" "${error_code}" "${path}" "${message}"
            ;;
        0)
            fail_step "${exit_code}" "${error_code}" "${path}" \
                "${message} -- rolled back to the previous working offline-bundle generation of ${service} (dpkg -i --force-downgrade --force-confold restored /var/lib/lesta/${service}/bundle-generations/previous, then restarted: ${systemd_units}); this node remains on its prior package generation, the new generation was rejected"
            ;;
        *)
            fail_step "${EXIT_ROLLBACK_FAILURE}" rollback_failed "/var/lib/lesta/${service}/bundle-generations/previous" \
                "${message} -- automatic rollback to the previous offline-bundle generation of ${service} ALSO failed after this health check failure; this node's ${service} package state may now be inconsistent and requires manual operator intervention, inspect /var/lib/lesta/${service}/bundle-generations/{current,previous} and dpkg/systemctl status for: ${systemd_units}"
            ;;
    esac
}
