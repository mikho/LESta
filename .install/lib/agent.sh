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
# Ensures the four agent directories exist with correct ownership,
# checksum-verifies agent_binary_src against node_health_manifest_file's
# own artifacts[] entry, then copies it into place. Does not call
# checkpoint_write and does not run any self-test.
agent_install_binary() {
    local agent_binary_src="$1" node_health_manifest="$2" expected_sha256

    install -d -m 0750 -o root -g lesta /etc/lesta/agent || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /etc/lesta/agent "failed to create /etc/lesta/agent"
    install -d -m 0750 -o root -g lesta /var/lib/lesta/agent || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta/agent "failed to create /var/lib/lesta/agent"
    install -d -m 0750 -o root -g lesta /var/log/lesta/agent || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/log/lesta/agent "failed to create /var/log/lesta/agent"
    install -d -m 0750 -o lesta-agent -g lesta /var/lib/lesta/agent/bin || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta/agent/bin "failed to create /var/lib/lesta/agent/bin"
    add_change node.health.v1 ensured /var/lib/lesta/agent "agent directories present with correct ownership"

    expected_sha256=$(manifest_artifact_sha256 "${node_health_manifest}" "${AGENT_ARTIFACT_NAME}")
    if [ -z "${expected_sha256}" ]; then
        fail_step "${EXIT_VERIFICATION_FAILURE}" artifact_not_declared "${node_health_manifest}" "node-health manifest has no ${AGENT_ARTIFACT_NAME} artifacts[] entry"
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
