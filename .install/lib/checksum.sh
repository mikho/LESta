# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/lib/checksum.sh
#
# SHA-256 helpers: verifying a file against a pinned digest, and computing
# the manifest digest reported in the installer's result JSON. No side
# effects beyond reading the files named by the caller.

# compute_sha256 <file> -> prints the file's lowercase hex sha256 digest.
compute_sha256() {
    local file="$1"

    sha256sum "${file}" | awk '{print $1}'
}

# verify_sha256 <file> <expected_hex_sha256>
# Returns 0 when file exists and its digest matches; 1 otherwise (missing
# file or mismatch), printing a one-line diagnostic to stderr in either case.
verify_sha256() {
    local file="$1" expected="$2" actual

    if [ ! -f "${file}" ]; then
        printf 'verify_sha256: %s does not exist\n' "${file}" >&2
        return 1
    fi

    actual="$(compute_sha256 "${file}")"

    if [ "${actual}" != "${expected}" ]; then
        printf 'verify_sha256: %s sha256 mismatch: expected %s, got %s\n' "${file}" "${expected}" "${actual}" >&2
        return 1
    fi

    return 0
}

# compute_manifest_digest <manifest-file> ... -> "sha256:<hex>" over the
# concatenated raw bytes of every manifest file named, in the exact order
# given. Callers must always pass base, firewall, node-health, then nginx (in
# that fixed order) so the digest is reproducible run to run.
compute_manifest_digest() {
    printf 'sha256:%s\n' "$(cat "$@" | sha256sum | awk '{print $1}')"
}

# verify_keyring_fingerprints <keyring_file> <fingerprint>... -> 0 when the
# keyring's primary keys are exactly the given full fingerprints (in any
# order), 1 otherwise. Requiring the exact set, not just the expected key, is
# what pins it: a forged file could carry the real public key next to its own.
verify_keyring_fingerprints() {
    local keyring="$1" actual expected

    shift

    actual=$(gpg --show-keys --with-colons "${keyring}" 2>/dev/null \
        | awk -F: '/^pub/ { primary = 1; next } /^fpr/ { if (primary) print $10; primary = 0 }' \
        | sort)
    expected=$(printf '%s\n' "$@" | sort)

    [ -n "${actual}" ] && [ "${actual}" = "${expected}" ]
}
