# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/lib/selftest.sh
#
# The capability-agnostic half of every leaf-service installer's
# bootstrap_node_health self-test: building an OperationEnvelope, invoking
# the just-placed agent binary over stdin/stdout, reading back its status,
# and issuing the matching cleanup `delete`. Each installer's own
# run_node_health_selftest stays local to that installer (its payload shape
# and result-envelope interpretation are genuinely capability-specific);
# only the plumbing around it is shared here.

# selftest_new_uuid -> a fresh random UUID, read from the kernel directly
# (no uuidgen/util-linux dependency; Linux-only, which every installer that
# sources this file already is).
selftest_new_uuid() {
    cat /proc/sys/kernel/random/uuid
}

# selftest_envelope <capability> <operation> <resource_id> <idem> <corr> <desired_state_version> <payload>
# -> a complete OperationEnvelope JSON object for the given operation.
selftest_envelope() {
    local capability="$1" operation="$2" resource_id="$3" idem="$4" corr="$5" dsv="$6" payload="$7"
    local issued_at deadline request_digest

    issued_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    deadline=$(date -u -d '+30 seconds' +%Y-%m-%dT%H:%M:%SZ)
    request_digest="sha256:$(printf '%s' "${payload}" | sha256sum | awk '{print $1}')"

    json_join_object \
        "$(json_kv_str "protocol_version" "1")" \
        "$(json_kv_str "capability" "${capability}")" \
        "$(json_kv_str "operation" "${operation}")" \
        "$(json_kv_str "resource_id" "${resource_id}")" \
        "$(json_kv_raw "desired_state_version" "${dsv}")" \
        "$(json_kv_str "idempotency_key" "${idem}")" \
        "$(json_kv_str "correlation_id" "${corr}")" \
        "$(json_kv_str "deadline" "${deadline}")" \
        "$(json_kv_str "issued_at" "${issued_at}")" \
        "$(json_kv_str "request_digest" "${request_digest}")" \
        "$(json_kv_raw "payload" "${payload}")"
}

# selftest_invoke_agent <envelope_json> -> the agent's raw stdout on success
# (return 0), or its combined output with a non-zero return on failure. Never
# passes any environment override: the shipped binary only ever knows each
# capability's own productionConfig() fixed paths (see
# agent/cmd/lesta-agent/main.go).
selftest_invoke_agent() {
    printf '%s' "$1" | "${AGENT_BINARY_DEST}"
}

# selftest_status_from_output <agent_stdout> -> the "status" field's value,
# or empty if not found.
selftest_status_from_output() {
    printf '%s\n' "$1" | sed -n 's/.*"status"[[:space:]]*:[[:space:]]*"\([a-z_]*\)".*/\1/p' | head -n1
}

# run_node_health_selftest_delete <capability> <resource_id> <payload> <idem> <corr>
# Issues the matching `delete` for the throwaway resource the caller's own
# run_node_health_selftest creates. Split out so the "create succeeded, now
# clean up" and "create's own status wasn't applied, still try to clean up"
# paths share one implementation. Logs a warning and returns 1 on any
# failure; never exits the script itself, so callers control what a failed
# cleanup means for the overall result.
run_node_health_selftest_delete() {
    local capability="$1" resource_id="$2" payload="$3" idem="$4" corr="$5"
    local envelope agent_out agent_status status_line

    envelope=$(selftest_envelope "${capability}" delete "${resource_id}" "${idem}" "${corr}" 2 "${payload}")

    agent_status=0
    agent_out=$(selftest_invoke_agent "${envelope}") || agent_status=$?

    if [ "${agent_status}" -ne 0 ]; then
        log_warn "self-test delete: agent exited ${agent_status}: $(printf '%s' "${agent_out}" | tr '\n' ' ')"
        return 1
    fi

    status_line=$(selftest_status_from_output "${agent_out}")
    if [ "${status_line}" != "applied" ]; then
        log_warn "self-test delete: agent returned status=${status_line:-unknown}, expected applied"
        return 1
    fi

    return 0
}
