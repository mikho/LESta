# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/lib/agent.sh
#
# Installs the vendored agent binary into its one fixed production
# location, checksum-verified against node-health's own manifest.json
# artifacts[] entry. Shared by every leaf-service installer's own
# bootstrap_node_health phase: this file only covers the directory/
# checksum/copy portion, which has no capability-specific behavior at all.
# Each installer still runs its own capability-specific self-test
# afterward, and calls checkpoint_write itself once that self-test passes.

AGENT_BINARY_DEST="/var/lib/lesta/agent/bin/lesta-agent"
AGENT_ARTIFACT_NAME="lesta-agent-linux-amd64"

# agent_install_binary <agent_binary_src> <node_health_manifest_file>
# Ensures the four agent directories exist with correct ownership, then
# makes AGENT_BINARY_DEST match node_health_manifest_file's own artifacts[]
# expected sha256, generation-aware:
#   - AGENT_BINARY_DEST already matches expected_sha256: a true no-op, the
#     source is never even looked at, and returns early.
#   - AGENT_BINARY_DEST exists but differs from expected_sha256: a real
#     generation swap. The currently-installed binary is backed up to
#     AGENT_BINARY_DEST.previous FIRST (agent_rollback_binary's only source
#     of truth), before agent_binary_src is even checksum-verified, so a
#     rejected new generation (a failed verify just below, or a failed
#     self-test the caller's own bootstrap_node_health phase runs
#     afterward) can always be undone.
#   - AGENT_BINARY_DEST does not exist at all: a genuinely fresh install, no
#     backup is ever created.
# Does not call checkpoint_write and does not run any self-test.
agent_install_binary() {
    local agent_binary_src="$1" node_health_manifest="$2" expected_sha256 dest_sha256

    install -d -m 0750 -o root -g lesta /etc/lesta/agent || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /etc/lesta/agent "failed to create /etc/lesta/agent"
    install -d -m 0750 -o root -g lesta /var/lib/lesta/agent || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta/agent "failed to create /var/lib/lesta/agent"
    install -d -m 0750 -o root -g lesta /var/log/lesta/agent || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/log/lesta/agent "failed to create /var/log/lesta/agent"
    install -d -m 0750 -o lesta-agent -g lesta /var/lib/lesta/agent/bin || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta/agent/bin "failed to create /var/lib/lesta/agent/bin"
    add_change node.health.v1 ensured /var/lib/lesta/agent "agent directories present with correct ownership"

    expected_sha256=$(manifest_artifact_sha256 "${node_health_manifest}" "${AGENT_ARTIFACT_NAME}")
    if [ -z "${expected_sha256}" ]; then
        fail_step "${EXIT_VERIFICATION_FAILURE}" artifact_not_declared "${node_health_manifest}" "node-health manifest has no ${AGENT_ARTIFACT_NAME} artifacts[] entry"
    fi

    if [ -f "${AGENT_BINARY_DEST}" ]; then
        dest_sha256=$(compute_sha256 "${AGENT_BINARY_DEST}")

        if [ "${dest_sha256}" = "${expected_sha256}" ]; then
            log_info "agent_install_binary: ${AGENT_BINARY_DEST} already at the target generation (sha256 ${expected_sha256}); no-op"
            return 0
        fi

        # A real generation swap, not a fresh install onto an empty
        # destination: back up the currently-installed binary first, using
        # the exact same atomic write-to-tmp-then-mv pattern used for the
        # main binary itself below, so a crash mid-backup never leaves a
        # half-written .previous file.
        cp "${AGENT_BINARY_DEST}" "${AGENT_BINARY_DEST}.previous.tmp" \
            || fail_step "${EXIT_MUTATION_FAILURE}" backup_failed "${AGENT_BINARY_DEST}.previous" "failed to copy the currently-installed agent binary (sha256 ${dest_sha256}) to ${AGENT_BINARY_DEST}.previous.tmp before installing a new generation"
        chmod 0750 "${AGENT_BINARY_DEST}.previous.tmp"
        chown lesta-agent:lesta "${AGENT_BINARY_DEST}.previous.tmp" 2>/dev/null || true
        mv -f "${AGENT_BINARY_DEST}.previous.tmp" "${AGENT_BINARY_DEST}.previous" \
            || fail_step "${EXIT_MUTATION_FAILURE}" backup_failed "${AGENT_BINARY_DEST}.previous" "failed to rename ${AGENT_BINARY_DEST}.previous.tmp into place"
        add_change node.health.v1 backed_up "${AGENT_BINARY_DEST}.previous" "previous agent binary generation (sha256 ${dest_sha256}) backed up before installing the new generation (sha256 ${expected_sha256})"
    fi

    if [ ! -f "${agent_binary_src}" ]; then
        fail_step "${EXIT_VERIFICATION_FAILURE}" artifact_missing "${agent_binary_src}" "vendored agent binary not found; run 'composer agent:build' before packaging a release"
    fi

    verify_sha256 "${agent_binary_src}" "${expected_sha256}" \
        || fail_step "${EXIT_VERIFICATION_FAILURE}" checksum_mismatch "${agent_binary_src}" "vendored agent binary sha256 does not match node-health manifest's artifacts[] entry"

    cp "${agent_binary_src}" "${AGENT_BINARY_DEST}.tmp" || fail_step "${EXIT_MUTATION_FAILURE}" copy_failed "${AGENT_BINARY_DEST}" "failed to copy agent binary into place"
    chmod 0750 "${AGENT_BINARY_DEST}.tmp"
    chown lesta-agent:lesta "${AGENT_BINARY_DEST}.tmp" 2>/dev/null || true
    mv -f "${AGENT_BINARY_DEST}.tmp" "${AGENT_BINARY_DEST}"
    add_change node.health.v1 installed "${AGENT_BINARY_DEST}" "agent binary copied from ${agent_binary_src}, sha256 verified against manifest, mode 0750 lesta-agent:lesta"
}

# agent_rollback_binary
# Restores AGENT_BINARY_DEST.previous back over the live AGENT_BINARY_DEST,
# using the same atomic write-to-tmp-then-mv pattern agent_install_binary
# itself uses. Two distinct non-zero return codes let a caller tell "there
# was nothing to roll back to" (a fresh install has no prior generation, so
# a failed self-test there is an ordinary failed fresh install, not a
# rollback failure) apart from "a real prior generation existed but
# restoring it also failed" (the node's agent binary may now be in an
# inconsistent state and needs manual operator intervention):
#   0 - restored successfully.
#   1 - nothing to restore: AGENT_BINARY_DEST.previous does not exist.
#   2 - a prior generation existed but the restore attempt itself failed.
agent_rollback_binary() {
    if [ ! -f "${AGENT_BINARY_DEST}.previous" ]; then
        log_info "agent_rollback_binary: no ${AGENT_BINARY_DEST}.previous to restore"
        return 1
    fi

    cp "${AGENT_BINARY_DEST}.previous" "${AGENT_BINARY_DEST}.tmp" || {
        log_error "agent_rollback_binary: failed to copy ${AGENT_BINARY_DEST}.previous to ${AGENT_BINARY_DEST}.tmp"
        return 2
    }
    chmod 0750 "${AGENT_BINARY_DEST}.tmp"
    chown lesta-agent:lesta "${AGENT_BINARY_DEST}.tmp" 2>/dev/null || true
    mv -f "${AGENT_BINARY_DEST}.tmp" "${AGENT_BINARY_DEST}" || {
        log_error "agent_rollback_binary: failed to rename ${AGENT_BINARY_DEST}.tmp into place over ${AGENT_BINARY_DEST}"
        return 2
    }

    add_change node.health.v1 rolled_back "${AGENT_BINARY_DEST}" "restored ${AGENT_BINARY_DEST}.previous over ${AGENT_BINARY_DEST} after a health check failure against the newly installed generation"
    log_info "agent_rollback_binary: restored ${AGENT_BINARY_DEST}.previous over ${AGENT_BINARY_DEST}"
    return 0
}

# agent_fail_selftest_with_rollback <exit_code> <error_code> <path> <message>
# Drop-in replacement for a bare "add_error + emit_result_and_exit failed"
# (or fail_step) call at the exact point a leaf installer's own
# run_node_health_selftest has just determined the freshly (re)installed
# agent binary failed its self-test. Capability-agnostic: lives here rather
# than duplicated in each of the six installers, since none of this
# decision depends on which capability's own self-test failed.
#   - no AGENT_BINARY_DEST.previous exists (a genuinely fresh install, no
#     prior generation to fall back to): behaves exactly like a plain
#     fail_step with the same exit_code/error_code/path/message, since this
#     is an ordinary failed fresh install, not a rejected upgrade.
#   - a prior generation existed and agent_rollback_binary restored it:
#     still fails with the SAME exit_code the self-test failure would have
#     used before rollback support existed, but the message is extended to
#     say the node was rolled back and remains on its previous binary
#     version, so an operator reading the output understands the new
#     generation was rejected, not that everything just failed unexplained.
#   - a prior generation existed but the restore attempt itself failed:
#     fails with EXIT_ROLLBACK_FAILURE instead, since both the rejected new
#     generation and the previous one are now in question and operator
#     intervention is required.
agent_fail_selftest_with_rollback() {
    local exit_code="$1" error_code="$2" path="$3" message="$4" rollback_status=0

    if [ ! -f "${AGENT_BINARY_DEST}.previous" ]; then
        fail_step "${exit_code}" "${error_code}" "${path}" "${message}"
    fi

    agent_rollback_binary || rollback_status=$?

    if [ "${rollback_status}" -eq 0 ]; then
        fail_step "${exit_code}" "${error_code}" "${path}" \
            "${message} -- rolled back to the previous working agent binary generation (${AGENT_BINARY_DEST}.previous restored over ${AGENT_BINARY_DEST}); this node remains on its prior binary version, the new generation was rejected"
    else
        fail_step "${EXIT_ROLLBACK_FAILURE}" rollback_failed "${AGENT_BINARY_DEST}" \
            "${message} -- automatic rollback to the previous agent binary generation ALSO failed after this health check failure; this node's agent binary may now be in an inconsistent state and requires manual operator intervention, inspect ${AGENT_BINARY_DEST} and ${AGENT_BINARY_DEST}.previous by hand"
    fi
}
