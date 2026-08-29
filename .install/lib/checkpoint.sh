# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/lib/checkpoint.sh
#
# Two on-node records, distinct in lifetime:
#   CHECKPOINT_PATH - transient. Records the most recently completed phase
#                     plus the manifest digest it ran against. Removed only
#                     after every apply phase has succeeded. Its presence
#                     across runs is what "interrupted, requires recovery"
#                     (exit 30) means in practice.
#   RELEASE_PATH    - persistent. Written only once the whole run has
#                     succeeded; records which release and manifest digest
#                     are now installed, so a later run can classify itself
#                     as fresh/repair/upgrade.
#
# Every phase function in install.sh is idempotent on its own (each
# re-verifies existing state before mutating), so nothing here ever causes a
# phase to be skipped outright; the checkpoint is a diagnostic and recovery
# record, not a short-circuit.
#
# CHECKPOINT_PATH/RELEASE_PATH are deliberately NOT declared (let alone
# exported) here: each installer sets its own local values before calling
# into this file's functions (nginx/install.sh's own
# /var/lib/lesta/install/nginx.checkpoint + /etc/lesta/nginx-release,
# bind9/install.sh's own /var/lib/lesta/install/bind9.checkpoint +
# /etc/lesta/bind9-release). A single shared, hardcoded pair of paths here
# was a latent clobbering bug: if a second leaf-service installer sourced
# this file unmodified, both installers would read/write the exact same
# checkpoint/release-marker files. (RELEASE_PATH itself was previously named
# NGINX_RELEASE_PATH; renamed here to its current, capability-agnostic name
# since nothing outside this file ever referenced the old literal name --
# verified by grep across .install/ -- so the rename is purely internal and
# changes no installer's on-disk behavior.) preflight.sh's
# preflight_classify_install_state also reads both as ambient variables, so
# they must already be set by the calling install.sh before that function
# (or any function in this file) is invoked.

# checkpoint_write <phase> <manifest_digest>
checkpoint_write() {
    local phase="$1" digest="$2" dir

    dir=$(dirname "${CHECKPOINT_PATH}")
    install -d -m 0750 "${dir}"

    printf '{"phase":"%s","manifest_digest":"%s","updated_at":"%s"}\n' \
        "${phase}" "${digest}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "${CHECKPOINT_PATH}"
}

# checkpoint_remove deletes the transient checkpoint after a fully
# successful run. Safe to call even if it never existed.
checkpoint_remove() {
    rm -f "${CHECKPOINT_PATH}"
}

# release_read_digest -> prints the manifest_digest recorded in
# RELEASE_PATH, or nothing (return 1) if it does not exist yet.
release_read_digest() {
    [ -f "${RELEASE_PATH}" ] || return 1

    sed -n 's/.*"manifest_digest":"\([^"]*\)".*/\1/p' "${RELEASE_PATH}"
}

# release_write <release_id> <manifest_digest>
# Written only after every apply phase has succeeded.
release_write() {
    local release_id="$1" digest="$2" dir

    dir=$(dirname "${RELEASE_PATH}")
    install -d -m 0750 -o root -g lesta "${dir}"

    printf '{"release":"%s","manifest_digest":"%s","installed_at":"%s"}\n' \
        "${release_id}" "${digest}" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "${RELEASE_PATH}"
}
