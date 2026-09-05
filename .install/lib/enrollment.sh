# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/lib/enrollment.sh
#
# One-shot HTTP client for the enrollment handshake against
# routes/agent.php's own POST /agent/v1/enroll endpoint (see
# app/Http/Controllers/Agent/AgentEnrollmentController.php). This is the
# only network call this installer family ever makes to the control plane
# itself; everything else in .install/ is purely node-local. Uses curl (a
# stock Ubuntu package, already a dependency of every other installer in
# this family) rather than adding any new tool.
#
# **Security note (see this service's own README.md for the full
# disclosure)**: the raw enrollment token and node credential are bearer
# secrets carried over plain HTTP by curl unless control_plane_url itself is
# an https:// URL. This installer never terminates or configures TLS of its
# own; it is the operator's own responsibility to pass an https://
# control-plane URL. Neither secret is ever written to this installer's own
# log output.

# enrollment_post_bootstrap <control_plane_url> <node_uuid> <token> <agent_version> <protocol_version>
# POSTs to <control_plane_url>/agent/v1/enroll, prints the raw response body
# to stdout on success (HTTP 2xx), returns non-zero on any curl/HTTP
# failure.
enrollment_post_bootstrap() {
    local control_plane_url="$1" node_uuid="$2" token="$3" agent_version="$4" protocol_version="$5"
    local payload http_code response_file

    payload=$(json_join_object \
        "$(json_kv_str "node_uuid" "${node_uuid}")" \
        "$(json_kv_str "enrollment_token" "${token}")" \
        "$(json_kv_str "agent_version" "${agent_version}")" \
        "$(json_kv_str "protocol_version" "${protocol_version}")")

    response_file=$(mktemp)
    http_code=$(curl -fsS -o "${response_file}" -w '%{http_code}' -X POST \
        -H 'Content-Type: application/json' \
        -d "${payload}" \
        "${control_plane_url}/agent/v1/enroll") || {
        rm -f "${response_file}"
        return 1
    }

    if [ "${http_code}" -lt 200 ] || [ "${http_code}" -ge 300 ]; then
        rm -f "${response_file}"
        return 1
    fi

    cat "${response_file}"
    rm -f "${response_file}"
}

# enrollment_extract_field <json_response> <field_name>
# Minimal, non-jq field extraction for a quoted string field in this
# installer's own flat, known response shape (never a general JSON parser).
# Matches lib/selftest.sh's own selftest_status_from_output precedent for
# extracting one known field via sed rather than adding a jq dependency to
# the target node.
enrollment_extract_field() {
    printf '%s\n' "$1" | sed -n "s/.*\"$2\"[[:space:]]*:[[:space:]]*\"\\([^\"]*\\)\".*/\\1/p" | head -n1
}

# enrollment_extract_number_field <json_response> <field_name>
# Same as enrollment_extract_field, but for an unquoted numeric field (e.g.
# heartbeat_interval_seconds), which json_kv_raw emits without surrounding
# quotes.
enrollment_extract_number_field() {
    printf '%s\n' "$1" | sed -n "s/.*\"$2\"[[:space:]]*:[[:space:]]*\\([0-9][0-9]*\\).*/\\1/p" | head -n1
}

# enrollment_write_credential <raw_credential>
# Atomic write-then-chmod-then-chown-then-mv, mirroring agent.sh's own
# pattern for the vendored binary. The raw credential is never logged.
enrollment_write_credential() {
    local credential="$1"

    install -d -m 0750 -o lesta-agent -g lesta /etc/lesta/agent 2>/dev/null || true
    printf '%s' "${credential}" > /etc/lesta/agent/node-credential.tmp
    chmod 0600 /etc/lesta/agent/node-credential.tmp
    chown lesta-agent:lesta /etc/lesta/agent/node-credential.tmp 2>/dev/null || true
    mv -f /etc/lesta/agent/node-credential.tmp /etc/lesta/agent/node-credential
}
