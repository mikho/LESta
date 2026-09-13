#!/bin/sh
# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# .install/services/mail/install.sh
#
# Bootstrap installer for the mail.smtp-imap.v1 capability: takes a bare
# Ubuntu 24.04/26.04 node (or one that already has other leaf-service
# capabilities bootstrapped) to a state where the Go agent's own
# mailProductionConfig() preconditions (agent/cmd/lesta-agent/main.go) are
# met and mail.smtp-imap.v1 is structurally installed and health-checked,
# per .install/INSTALLER-CONTRACT.md.
#
# Mirrors bind9/install.sh's own overall structure (ports + firewall +
# include-line preflight), doubled and adapted for two subsystems (Exim and
# Dovecot) instead of one. The one real structural difference from every
# other installer in this family: Exim's own prerequisite is NOT an
# include-line into a pre-existing, operator-owned file the way nginx.conf/
# named.conf/dovecot.conf are. Exim's config format has exactly one begin
# acl/begin routers/begin transports/begin authenticators section each, and
# Debian's default split-config assembly already defines all four --
# .include-ing a second, separate file into that produces duplicate-section
# errors, not a working config (confirmed directly against
# agent/internal/capability/mail/config.go's own EximStaticConfPath doc
# comment, which documents this exact constraint). So install_mail below
# takes full ownership of /etc/exim4/exim4.conf itself (forcing Debian's
# own dc_use_split_config='false', its own sanctioned "I want full manual
# control" switch), writing one complete, self-contained config every
# apply, exactly matching the shape agent/internal/capability/mail/
# harness_test.go's own disposable-Exim proof already used. Dovecot's own
# config format has no such restriction (its real packaging already ships
# `!include_try conf.d/*.conf` by default), so DovecotConfPath keeps the
# familiar separate-file-plus-include-line shape every other installer
# uses.
#
# TLS for the submission (587)/smtps (465)/imaps (993) listeners reuses the
# existing WebDomain+ACME flow rather than inventing new integration:
# --mail-hostname <fqdn> names a real hostname an operator has already
# created a WebDomain for (any web profile) and let Phase 11's own real
# ACME issuance produce a certificate for, at tls.acme.v1's own real,
# fixed path convention (/var/lib/lesta/acme/certs/<fqdn>/{fullchain,
# privkey}.pem). Preflight fails closed if that certificate doesn't exist
# yet -- this installer never issues one itself.
#
# --offline-bundle support mirrors mariadb/install.sh's own precedent
# exactly (the closest existing multi-package bundle): exim4-daemon-heavy,
# dovecot-imapd/dovecot-lmtpd/dovecot-sieve, clamav-daemon/clamav-freshclam,
# and spamassassin are all vendored and retained together as one bundle/one
# generation, since they are installed and rolled back as a unit here, the
# same "one shared package underlies both" framing mariadb/install.sh's own
# comment uses for its two systemd instances.
#
# Real antivirus/antispam scanning: clamav-daemon (clamd, a real virus
# scanner) and spamassassin (spamd, a real spam scorer) are installed and
# health-probed like every other real daemon this installer manages.
# domains.list's own sibling antivirus.list/antispam.list flag files (the Go
# agent has rendered these unconditionally since Phase 30, previously never
# read by anything) now gate a new acl_check_data: stanza via ACL variables
# ($acl_m_av/$acl_m_spam) accumulated once per accepted recipient in
# acl_check_rcpt, since a single message's DATA phase has no single
# unambiguous "$domain" when multiple hosted-domain recipients are in the
# same transaction -- the RCPT-time accumulation is message-scoped and
# survives to DATA time, exactly the mechanism Exim's own ACL variable
# design exists for. Both `malware`/`spam` ACL conditions use /defer_ok: a
# scanner daemon that is briefly unreachable defers the message (a real,
# retried 4xx) rather than either hard-rejecting legitimate mail or silently
# accepting everything unscanned -- the safer of Exim's own two documented
# failure modes for content scanning.
#
# Real CI is this scanning path's own proof, not a local dry run: real
# ClamAV virus detection is proven with an EICAR test string (the standard,
# harmless, industry-wide test signature every AV engine recognizes without
# needing a real virus sample), and real SpamAssassin detection with
# GTUBE (its own equivalent standard test string). Exim itself never
# eagerly connects to av_scanner/spamd_address at config-load or restart
# time (confirmed: these are plain option strings, connected to lazily only
# when a real message actually reaches the DATA ACL) -- unlike Dovecot's own
# postinst-restart-ordering hazard elsewhere in this file, a broken or
# not-yet-started clamd/spamd would NOT fail exim4 -bV or systemctl restart
# exim4 outright, so this installer adds its own explicit, hard-failing
# health probe for both daemons instead (mirroring mail_health_probe's own
# real TCP-probe convention), rather than trusting Exim's own lenient
# config-load behavior to ever catch a daemon that never actually started.
set -eu

# --- constants -------------------------------------------------------------

SCRIPT_VERSION="1.0.0"
RELEASE_ID="2026.09.13"
MAIL_SMTP_IMAP_CAPABILITY="mail.smtp-imap.v1"

SCRIPT_DIR=$(CDPATH='' cd -- "$(dirname -- "$0")" && pwd)
INSTALL_ROOT=$(CDPATH='' cd -- "${SCRIPT_DIR}/../.." && pwd)
REPO_ROOT=$(CDPATH='' cd -- "${INSTALL_ROOT}/.." && pwd)

# shellcheck source=../../lib/run.sh
. "${INSTALL_ROOT}/lib/run.sh"
# shellcheck source=../../lib/json.sh
. "${INSTALL_ROOT}/lib/json.sh"
# shellcheck source=../../lib/checksum.sh
. "${INSTALL_ROOT}/lib/checksum.sh"
# shellcheck source=../../lib/log.sh
. "${INSTALL_ROOT}/lib/log.sh"
# shellcheck source=../../lib/checkpoint.sh
. "${INSTALL_ROOT}/lib/checkpoint.sh"
# shellcheck source=../../lib/preflight.sh
. "${INSTALL_ROOT}/lib/preflight.sh"
# shellcheck source=../../lib/result.sh
. "${INSTALL_ROOT}/lib/result.sh"
# shellcheck source=../../lib/prepare-config.sh
. "${INSTALL_ROOT}/lib/prepare-config.sh"
# shellcheck source=../../lib/firewall.sh
. "${INSTALL_ROOT}/lib/firewall.sh"
# shellcheck source=../../lib/agent.sh
. "${INSTALL_ROOT}/lib/agent.sh"
# shellcheck source=../../lib/selftest.sh
. "${INSTALL_ROOT}/lib/selftest.sh"
# shellcheck source=../../lib/offline-bundle.sh
. "${INSTALL_ROOT}/lib/offline-bundle.sh"

BASE_MANIFEST="${INSTALL_ROOT}/base/manifest.json"
FIREWALL_MANIFEST="${INSTALL_ROOT}/services/firewall/manifest.json"
NODE_HEALTH_MANIFEST="${INSTALL_ROOT}/services/node-health/manifest.json"
MAIL_MANIFEST="${INSTALL_ROOT}/services/mail/manifest.json"

AGENT_BINARY_SRC="${REPO_ROOT}/agent/dist/lesta-agent-linux-amd64"

# Fixed production paths/identity. Mirrors agent/cmd/lesta-agent/main.go's
# own mailProductionConfig() exactly -- these two files are the only two
# places these literals may ever appear; keep them in lockstep.
EXIM_CONF_PATH="/etc/exim4/exim4.conf"
EXIM_UPDATE_CONF="/etc/exim4/update-exim4.conf.conf"
EXIM_LIVE_DIR="/etc/exim4/lesta.d"
EXIM_DATA_DIR="/etc/exim4/lesta.d/data"
EXIM_PID_FILE="/var/run/exim4/exim.pid"
EXIM_SPOOL_DIR="/var/spool/exim4"

DOVECOT_CONF_PATH="/etc/dovecot/dovecot.conf"
LESTA_DOVECOT_CONF="/etc/dovecot/lesta.conf"
DOVECOT_LIVE_DIR="/etc/dovecot/lesta.d"
DOVECOT_PASSWD_PATH="/etc/dovecot/lesta.d/passwd"

MAIL_STATE_ROOT="/var/lib/lesta/mail"
SIEVE_DIR="/var/lib/lesta/mail/sieve"
DKIM_KEY_ROOT="/var/lib/lesta/mail/dkim"
VMAIL_HOME="/var/lib/lesta/mail/vmail"

ACME_CERTS_ROOT="/var/lib/lesta/acme/certs"

# Real, fixed paths ClamAV's/SpamAssassin's own Debian packaging default to
# (clamav-daemon's own /etc/clamav/clamd.conf LocalSocket; spamd's own
# always-loopback-only listener) -- never operator-configurable, matching
# every other daemon endpoint literal in this file.
CLAMD_SOCKET="/run/clamav/clamd.ctl"
SPAMD_PORT=783

# CHECKPOINT_PATH/RELEASE_PATH: this installer's own paths, distinct from
# every other leaf-service installer's own (see lib/checkpoint.sh's own top
# comment).
export CHECKPOINT_PATH="/var/lib/lesta/install/mail.checkpoint"
export RELEASE_PATH="/etc/lesta/mail-release"

# --- globals (all pre-declared for `set -u` safety) -------------------------

MODE=""
YES=0
MAIL_HOSTNAME=""
OFFLINE_BUNDLE=""
RUN_ID=""
MANIFEST_DIGEST=""
CHANGES=""
ERRORS=""

# --- usage / argument parsing ----------------------------------------------

usage() {
    cat <<'USAGE' >&2
Usage: install.sh --dry-run|--apply|--version|--prepare-config [--mail-hostname <fqdn>] [--offline-bundle <path>] [--yes] [--help]

  --dry-run                Run preflight and report what would change. No mutation.
  --apply                  Apply the installer. Requires --yes and --mail-hostname.
  --version                Print installer version and exit.
  --prepare-config         Opt-in alternative to the documented manual
                           prerequisite: automatically appends
                           '!include /etc/dovecot/lesta.conf' to
                           dovecot.conf if not already present, then exits
                           -- it never runs preflight or performs any of
                           --dry-run/--apply's own mutations. There is no
                           equivalent step for Exim: this installer writes
                           /etc/exim4/exim4.conf itself outright (see this
                           file's own top comment for why). Requires --yes.
                           Idempotent. Never invoked implicitly.
  --mail-hostname <fqdn>   Required with --dry-run/--apply. The real
                           hostname this node's mail service identifies as
                           (its own TLS certificate's subject, used for
                           submission/smtps/imaps). A WebDomain for this
                           hostname must already exist and have a real
                           ACME-issued certificate at
                           /var/lib/lesta/acme/certs/<fqdn>/{fullchain,
                           privkey}.pem before this installer will apply --
                           see this file's own top comment.
  --offline-bundle <path>  Optional. Installs exim4-daemon-heavy and
                           dovecot-imapd/dovecot-lmtpd/dovecot-sieve from a
                           pre-vendored, checksum-verified bundle (see
                           .install/scripts/build-release.sh) instead of a
                           live apt-get install, requiring no network
                           access. All four packages are retained and
                           rolled back together as one generation.
  --yes                    Required with --apply/--prepare-config: non-interactive confirmation.
  --help                   Print this message.
USAGE
}

fail_invocation() {
    printf 'install.sh: %s\n' "$1" >&2
    usage
    add_error invalid_invocation "$1" ""
    emit_result_and_exit failed "${EXIT_INVALID_INVOCATION}"
}

parse_args() {
    if [ "$#" -eq 0 ]; then
        fail_invocation "exactly one of --dry-run, --apply, --version, or --prepare-config is required"
    fi

    while [ "$#" -gt 0 ]; do
        case "$1" in
            --dry-run)
                [ -z "${MODE}" ] || fail_invocation "only one of --dry-run/--apply/--version/--prepare-config may be given"
                MODE="dry-run"
                shift
                ;;
            --apply)
                [ -z "${MODE}" ] || fail_invocation "only one of --dry-run/--apply/--version/--prepare-config may be given"
                MODE="apply"
                shift
                ;;
            --version)
                [ -z "${MODE}" ] || fail_invocation "only one of --dry-run/--apply/--version/--prepare-config may be given"
                MODE="version"
                shift
                ;;
            --prepare-config)
                [ -z "${MODE}" ] || fail_invocation "only one of --dry-run/--apply/--version/--prepare-config may be given"
                MODE="prepare-config"
                shift
                ;;
            --mail-hostname)
                [ "$#" -ge 2 ] || fail_invocation "--mail-hostname requires a value"
                MAIL_HOSTNAME="$2"
                shift 2
                ;;
            --mail-hostname=*)
                MAIL_HOSTNAME="${1#--mail-hostname=}"
                shift
                ;;
            --offline-bundle)
                [ "$#" -ge 2 ] || fail_invocation "--offline-bundle requires a value"
                OFFLINE_BUNDLE="$2"
                shift 2
                ;;
            --offline-bundle=*)
                OFFLINE_BUNDLE="${1#--offline-bundle=}"
                shift
                ;;
            --yes)
                YES=1
                shift
                ;;
            --help)
                usage
                exit "${EXIT_OK}"
                ;;
            *)
                fail_invocation "unrecognized argument: $1"
                ;;
        esac
    done
}

# hostname_pattern-equivalent check: lowercase letters/digits/hyphens per
# label, at least one dot, mirroring agent/internal/capability/mail's own
# domainPattern shape exactly (this installer's --mail-hostname ultimately
# becomes primary_hostname/tls_certificate lookups inside exim4.conf, so it
# must be a real, syntactically valid hostname).
validate_mail_hostname() {
    case "${MAIL_HOSTNAME}" in
        '') return 1 ;;
    esac

    printf '%s' "${MAIL_HOSTNAME}" | grep -Eq '^[a-z0-9]([a-z0-9-]*[a-z0-9])?(\.[a-z0-9]([a-z0-9-]*[a-z0-9])?)+$'
}

validate_args() {
    case "${MODE}" in
        version)
            return 0
            ;;
        prepare-config)
            if [ "${YES}" -ne 1 ]; then
                fail_invocation "--prepare-config requires --yes"
            fi
            return 0
            ;;
        dry-run | apply) ;;
        *)
            fail_invocation "exactly one of --dry-run, --apply, --version, or --prepare-config is required"
            ;;
    esac

    if [ "${MODE}" = "apply" ] && [ "${YES}" -ne 1 ]; then
        fail_invocation "--apply requires --yes"
    fi

    if ! validate_mail_hostname; then
        fail_invocation "--mail-hostname <fqdn> is required and must be a valid hostname (got: '${MAIL_HOSTNAME}')"
    fi
}

# --- result accumulation / emission -----------------------------------------

manifest_capabilities_required_json() {
    local items item lines=""

    items=$(manifest_extract_array "${MAIL_MANIFEST}" "depends_on")

    while IFS= read -r item; do
        [ -n "${item}" ] || continue
        lines=$(append_line "${lines}" "$(json_str "${item}")")
    done <<ITEMS
${items}
ITEMS

    json_array_from_lines "${lines}"
}

emit_result_and_exit() {
    local status="$1" exit_code="$2" changes_json errors_json required_json provided_json result

    changes_json=$(json_array_from_lines "${CHANGES}")
    errors_json=$(json_array_from_lines "${ERRORS}")
    required_json=$(manifest_capabilities_required_json)
    provided_json=$(json_array_from_lines "$(json_str "${MAIL_SMTP_IMAP_CAPABILITY}")")

    result=$(json_join_object \
        "$(json_kv_str "schema_version" "1")" \
        "$(json_kv_str "installer" "lesta-bootstrap")" \
        "$(json_kv_str "service" "mail")" \
        "$(json_kv_str "mode" "${MODE:-unset}")" \
        "$(json_kv_str "status" "${status}")" \
        "$(json_kv_raw "exit_code" "${exit_code}")" \
        "$(json_kv_str "release" "${RELEASE_ID}")" \
        "$(json_kv_str "manifest_digest" "${MANIFEST_DIGEST}")" \
        "$(json_kv_raw "capabilities_provided" "${provided_json}")" \
        "$(json_kv_raw "capabilities_required" "${required_json}")" \
        "$(json_kv_raw "changes" "${changes_json}")" \
        "$(json_kv_raw "errors" "${errors_json}")")

    printf '%s\n' "${result}"
    exit "${exit_code}"
}

emit_version_and_exit() {
    MODE="version"
    emit_result_and_exit ok "${EXIT_OK}"
}

mail_would_install_note() {
    if [ -n "${OFFLINE_BUNDLE}" ]; then
        printf 'every vendored .deb in %s would be sha256-verified against %s/%s, then installed offline via dpkg -i (no network access required)%s' \
            "${OFFLINE_BUNDLE}" "${OFFLINE_BUNDLE}" "${BUNDLE_MANIFEST_FILENAME}" "$(offline_bundle_would_retain_note mail "${OFFLINE_BUNDLE}" exim4-daemon-heavy)"
    else
        printf 'exim4-daemon-heavy, dovecot-imapd/dovecot-lmtpd/dovecot-sieve, clamav-daemon/clamav-freshclam, and spamassassin/spamd would be installed'
    fi
}

emit_dry_run_result_and_exit() {
    local install_state
    install_state=$(preflight_classify_install_state)

    add_change base.os.v1 would_ensure /etc/lesta "base directories and lesta/lesta-agent identity would be created or verified; install-state classification: ${install_state}"
    add_change firewall.baseline.v1 would_apply "${NFT_TABLE_PATH}" "deny-by-default nftables table would be loaded and ${FIREWALL_UNIT_PATH} installed and enabled, unioned with any other service already registered on this node"
    add_change node.health.v1 would_install "${AGENT_BINARY_DEST}" "vendored agent binary would be checksum-verified and copied into place, then self-tested by creating and deleting a throwaway mail domain against the real, just-installed exim/dovecot"
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" would_install "${OFFLINE_BUNDLE}" "$(mail_would_install_note); ${EXIM_CONF_PATH} would be written outright (non-split mode) with a real ACL/router/transport/authenticator config (including a real acl_check_data malware/spam gate) for hostname ${MAIL_HOSTNAME}, using the certificate at ${ACME_CERTS_ROOT}/${MAIL_HOSTNAME}/; ${LESTA_DOVECOT_CONF} would be written and !include-d from ${DOVECOT_CONF_PATH}; the vmail system identity would be created; all four services (exim4, dovecot, clamav-daemon, spamassassin) would be enabled, restarted, and health-probed"

    emit_result_and_exit would_change "${EXIT_OK}"
}

emit_apply_success_and_exit() {
    log_info "install.sh apply completed successfully"
    emit_result_and_exit applied "${EXIT_OK}"
}

# --- preflight orchestration --------------------------------------------

preflight_check_dovecot_include() {
    local status=0

    check_lesta_include_present "${DOVECOT_CONF_PATH}" "${LESTA_DOVECOT_CONF}" "!include" || status=$?

    case "${status}" in
        0)
            return 0
            ;;
        1)
            add_error dovecot_conf_missing "dovecot.conf not found at ${DOVECOT_CONF_PATH}; install dovecot-core first, then add this line inside dovecot.conf itself: !include ${LESTA_DOVECOT_CONF}" "${DOVECOT_CONF_PATH}"
            return 1
            ;;
        *)
            add_error dovecot_conf_missing_include "${DOVECOT_CONF_PATH} exists but has no !include ${LESTA_DOVECOT_CONF} line. Add that exact line by hand, or rerun with --prepare-config." "${DOVECOT_CONF_PATH}"
            return 1
            ;;
    esac
}

run_prepare_config() {
    local status=0

    insert_lesta_include_if_missing "${DOVECOT_CONF_PATH}" "${LESTA_DOVECOT_CONF}" "!include" "!include ${LESTA_DOVECOT_CONF}" append || status=$?

    case "${status}" in
        0)
            add_change "${MAIL_SMTP_IMAP_CAPABILITY}" config_prepared "${DOVECOT_CONF_PATH}" "!include ${LESTA_DOVECOT_CONF} is now present in dovecot.conf itself (either it was already there, a no-op, or it was just appended)"
            emit_result_and_exit applied "${EXIT_OK}"
            ;;
        1)
            add_error dovecot_conf_missing "dovecot.conf not found at ${DOVECOT_CONF_PATH}; install dovecot-core first (apt-get install -y dovecot-core), then rerun --prepare-config or add the include line by hand." "${DOVECOT_CONF_PATH}"
            emit_result_and_exit failed "${EXIT_PREFLIGHT_CONFLICT}"
            ;;
        *)
            add_error dovecot_conf_prepare_failed "failed to append the include line to ${DOVECOT_CONF_PATH}; investigate manually before retrying." "${DOVECOT_CONF_PATH}"
            emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
            ;;
    esac
}

# preflight_check_conflicting_mta_packages
# Refuses to displace an existing, operator-managed alternative MTA
# package, mirroring bind9/install.sh's own identical conflicting-DNS-
# resolver-package precedent. exim4-daemon-light/exim4-daemon-heavy are
# never flagged here (installing the right one is this installer's own
# job); a DIFFERENT MTA entirely is what this refuses to displace.
preflight_check_conflicting_mta_packages() {
    if dpkg -l 2>/dev/null | grep -E '^ii[[:space:]]+(postfix|sendmail|sendmail-bin|opensmtpd|msmtp-mta)\b' >/dev/null 2>&1; then
        add_error conflicting_package "postfix, sendmail, opensmtpd, or msmtp-mta is already installed (dpkg -l); this installer refuses to displace an existing MTA package" ""
        return 1
    fi

    return 0
}

# preflight_check_mail_tls_certificate
# Fails closed rather than installing with no real TLS material at all: see
# this file's own top comment for the WebDomain+ACME reuse this depends on.
preflight_check_mail_tls_certificate() {
    local cert_dir="${ACME_CERTS_ROOT}/${MAIL_HOSTNAME}"

    if [ ! -f "${cert_dir}/fullchain.pem" ] || [ ! -f "${cert_dir}/privkey.pem" ]; then
        add_error mail_tls_certificate_missing "no certificate found at ${cert_dir}/{fullchain,privkey}.pem for --mail-hostname ${MAIL_HOSTNAME}; create a WebDomain for this hostname first (any web profile) and let the existing ACME flow issue its certificate, then rerun this installer" "${cert_dir}"
        return 1
    fi

    return 0
}

run_preflight() {
    local os_id os_version_id arch supported dir protocol port failed=0

    if [ "$(id -u)" -ne 0 ]; then
        add_error not_root "install.sh must run as root (uid 0)" ""
        emit_result_and_exit failed "${EXIT_UNSUPPORTED_PLATFORM}"
    fi

    os_id=$(preflight_os_release_field ID)
    os_version_id=$(preflight_os_release_field VERSION_ID)
    supported=$(manifest_extract_array "${MAIL_MANIFEST}" "supported_ubuntu")

    if [ "${os_id}" != "ubuntu" ] || ! printf '%s\n' "${supported}" | grep -Fxq "${os_version_id}"; then
        add_error unsupported_os "detected ${os_id:-unknown} ${os_version_id:-unknown}; supported: $(printf '%s' "${supported}" | tr '\n' ' ')" "/etc/os-release"
        emit_result_and_exit failed "${EXIT_UNSUPPORTED_PLATFORM}"
    fi

    arch=$(dpkg --print-architecture)
    if [ "${arch}" != "amd64" ]; then
        add_error unsupported_architecture "detected architecture ${arch}; only amd64 is supported (the vendored agent binary is amd64-only)" ""
        emit_result_and_exit failed "${EXIT_UNSUPPORTED_PLATFORM}"
    fi

    log_info "platform ok: ubuntu ${os_version_id} ${arch} kernel=$(uname -r)"

    for dir in /etc /var/lib /var/log; do
        preflight_check_capacity "${dir}" || failed=1
    done

    # Port 993 is owned by dovecot, not exim4: an idempotent re-apply must
    # recognize each daemon's own already-listening process as expected,
    # not just exim4's -- discovered via a real CI failure where a
    # perfectly healthy re-apply was rejected as "port 993/tcp is already
    # in use by 'dovecot'" because this loop named exim4 as the expected
    # owner for every port uniformly.
    while IFS=' ' read -r protocol port; do
        if [ -z "${protocol}" ] || [ -z "${port}" ]; then
            continue
        fi

        case "${port}" in
            993) preflight_check_port_free "${port}" "${protocol}" dovecot || failed=1 ;;
            *) preflight_check_port_free "${port}" "${protocol}" exim4 || failed=1 ;;
        esac
    done <<PORTS
$(manifest_extract_port_specs "${MAIL_MANIFEST}")
PORTS

    preflight_check_conflicting_mta_packages || failed=1
    preflight_check_lesta_identity || failed=1
    preflight_check_dovecot_include || failed=1
    preflight_check_mail_tls_certificate || failed=1

    if [ -n "${OFFLINE_BUNDLE}" ]; then
        preflight_check_offline_bundle_present "${OFFLINE_BUNDLE}" || failed=1
    fi

    if [ "${failed}" -ne 0 ]; then
        emit_result_and_exit failed "${EXIT_PREFLIGHT_CONFLICT}"
    fi

    log_info "preflight passed"
}

# --- phase 1: bootstrap_base -------------------------------------------
#
# Identical to every other leaf-service installer's own bootstrap_base
# (capability-agnostic). Duplicated rather than shared, matching this
# project's own established "concrete shared lib files only" scope.

ensure_lesta_group() {
    getent group lesta >/dev/null 2>&1 || groupadd --system lesta
}

bootstrap_base() {
    log_info "bootstrap_base: ensuring lesta group/user and base directories"

    ensure_lesta_group

    if ! getent passwd lesta-agent >/dev/null 2>&1; then
        useradd --system --gid lesta --home-dir /var/lib/lesta --no-create-home \
            --shell /usr/sbin/nologin --comment "LESta node agent" lesta-agent \
            || fail_step "${EXIT_MUTATION_FAILURE}" useradd_failed /etc/passwd "failed to create system user lesta-agent"
        add_change base.os.v1 created /etc/passwd "created system user lesta-agent (via useradd, NSS-mediated)"
    else
        add_change base.os.v1 verified /etc/passwd "system user lesta-agent already exists"
    fi

    install -d -m 0750 -o root -g lesta /etc/lesta || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /etc/lesta "failed to create /etc/lesta"
    install -d -m 0751 -o root -g lesta /var/lib/lesta || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta "failed to create /var/lib/lesta"
    install -d -m 0750 -o root -g lesta /var/log/lesta || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/log/lesta "failed to create /var/log/lesta"
    add_change base.os.v1 ensured /etc/lesta "directory present, mode 0750 root:lesta"
    add_change base.layout.v1 ensured /var/lib/lesta "directory present, mode 0750 root:lesta"
    add_change base.layout.v1 ensured /var/log/lesta "directory present, mode 0750 root:lesta"

    update-ca-certificates >/dev/null 2>&1 || true
    add_change base.tls.v1 refreshed /etc/ssl/certs "update-ca-certificates run"

    checkpoint_write bootstrap_base "${MANIFEST_DIGEST}"
    log_info "bootstrap_base complete"
}

# --- phase 2: bootstrap_firewall_baseline -------------------------------
#
# Shared (lib/firewall.sh): registers mail's own four ports (25/465/587/993
# tcp) into its own fragment, then renders the union of every service's
# registered ports. See lib/firewall.sh's own top comment.

# --- phase 3: install_mail -----------------------------------------------

# mail_write_file <path> <content> <mode> writes content to path atomically
# (staged then renamed), mirroring mariadb/install.sh's own real
# structural-.cnf-fragment precedent exactly: unlike nginx/bind9's
# operator-preserved distro files, both files this function writes
# (exim4.conf, lesta.conf) are entirely install.sh-owned, rewritten in full
# on every apply.
mail_write_file() {
    local path="$1" content="$2" mode="$3"

    printf '%s' "${content}" > "${path}.tmp" || fail_step "${EXIT_MUTATION_FAILURE}" config_write_failed "${path}" "failed to write ${path}.tmp"
    chmod "${mode}" "${path}.tmp" || fail_step "${EXIT_MUTATION_FAILURE}" config_write_failed "${path}" "failed to chmod ${path}.tmp"
    mv -f "${path}.tmp" "${path}" || fail_step "${EXIT_MUTATION_FAILURE}" config_write_failed "${path}" "failed to rename ${path}.tmp into place"
}

# render_exim_conf prints the complete, self-contained Exim config this
# installer owns outright. Real relay-safety (only a locally-hosted,
# actually-existing mailbox may receive anonymous mail; anything else is
# refused), real SMTP AUTH on the submission ports (587/465) via Dovecot's
# own SASL backend, real DKIM signing on outbound mail for any domain with
# a real generated key (dkim_keys.list, rendered by the Go agent), and a
# real dnslookup router so authenticated users' own outbound mail actually
# reaches the internet -- not just the local relay-safety ACL. AUTH is only
# ever advertised once TLS is active (auth_advertise_hosts), so a
# credential is never sent in the clear.
render_exim_conf() {
    cat <<EXIMCONF
# Managed entirely by LESta's mail installer
# (.install/services/mail/install.sh). Any hand edits are overwritten on
# the next --apply.

exim_user = Debian-exim
exim_group = Debian-exim
spool_directory = ${EXIM_SPOOL_DIR}
log_file_path = /var/log/exim4/%slog
pid_file_path = ${EXIM_PID_FILE}
primary_hostname = ${MAIL_HOSTNAME}
qualify_domain = ${MAIL_HOSTNAME}
never_users = root

daemon_smtp_ports = 25 : 587
tls_on_connect_ports = 465
local_interfaces = <; 0.0.0.0

tls_certificate = ${ACME_CERTS_ROOT}/${MAIL_HOSTNAME}/fullchain.pem
tls_privatekey = ${ACME_CERTS_ROOT}/${MAIL_HOSTNAME}/privkey.pem
tls_advertise_hosts = *
auth_advertise_hosts = \${if def:tls_in_cipher {*}{}}

domainlist local_domains = lsearch;${EXIM_DATA_DIR}/domains.list

acl_smtp_mail = acl_check_mail
acl_smtp_rcpt = acl_check_rcpt
acl_smtp_data = acl_check_data

av_scanner = clamd:${CLAMD_SOCKET}
spamd_address = 127.0.0.1 ${SPAMD_PORT}

begin acl

acl_check_mail:
  accept

acl_check_rcpt:
  accept  hosts = :

  deny    condition = \${if or{{eq{\$received_port}{587}}{eq{\$received_port}{465}}}}
          !authenticated = *
          message = "authentication required"

  accept  authenticated = *

  warn    domains = +local_domains
          condition = \${lookup{\$local_part@\$domain}lsearch{${EXIM_DATA_DIR}/accounts.list}{yes}{no}}
          set acl_m_av = \${if eq{\${lookup{\$domain}lsearch{${EXIM_DATA_DIR}/antivirus.list}{1}{0}}}{1}{1}{\$acl_m_av}}
          set acl_m_spam = \${if eq{\${lookup{\$domain}lsearch{${EXIM_DATA_DIR}/antispam.list}{1}{0}}}{1}{1}{\$acl_m_spam}}

  accept  domains = +local_domains
          condition = \${lookup{\$local_part@\$domain}lsearch{${EXIM_DATA_DIR}/accounts.list}{yes}{no}}

  deny    domains = +local_domains
          message = "no such mailbox"

  deny    message = "relay not permitted"

# acl_m_av/acl_m_spam are message-scoped ACL variables accumulated once per
# accepted local recipient above (the "warn" immediately before each real
# accept), never reset between recipients: a single DATA transaction can
# carry recipients across multiple hosted domains with different
# antivirus_enabled/antispam_enabled settings, and there is no single
# unambiguous domain at DATA time the way there is per-recipient in
# acl_check_rcpt. Scanning applies if ANY accepted recipient's own domain
# opted in, matching what an admin who enabled it for their domain expects:
# every message actually delivered there gets scanned, not just messages
# where it happens to be the sole recipient. Both conditions use /defer_ok:
# a clamd/spamd that is briefly unreachable defers the message (a real,
# retried 4xx) rather than either hard-rejecting legitimate mail or
# silently accepting it unscanned.
acl_check_data:
  deny    condition = \${if eq{\$acl_m_av}{1}{1}{0}}
          malware = */defer_ok
          message = "This message contains a virus (\$malware_name) and was rejected"

  deny    condition = \${if eq{\$acl_m_spam}{1}{1}{0}}
          spam = Debian-exim/defer_ok
          message = "This message was rejected as spam (score: \$spam_score)"

  accept

begin routers

lesta_virtual_router:
  driver = accept
  domains = +local_domains
  condition = \${lookup{\$local_part@\$domain}lsearch{${EXIM_DATA_DIR}/accounts.list}{yes}{no}}
  transport = lesta_lmtp_delivery

lesta_dnslookup_router:
  driver = dnslookup
  domains = ! +local_domains
  transport = remote_smtp
  ignore_target_hosts = <; 0.0.0.0 ; 127.0.0.0/8 ; ::1
  no_more

begin transports

remote_smtp:
  driver = smtp
  dkim_domain = \$sender_address_domain
  dkim_selector = lesta1
  dkim_private_key = \${lookup{\$sender_address_domain}lsearch{${EXIM_DATA_DIR}/dkim_keys.list}}

lesta_lmtp_delivery:
  driver = lmtp
  socket = /var/run/dovecot/lmtp

begin authenticators

dovecot_plain:
  driver = dovecot
  public_name = PLAIN
  server_socket = /var/run/dovecot/auth-client
  server_set_id = \$auth1

dovecot_login:
  driver = dovecot
  public_name = LOGIN
  server_socket = /var/run/dovecot/auth-client
  server_set_id = \$auth1
EXIMCONF
}

# render_dovecot_conf prints the complete LESta-owned Dovecot fragment
# !include-d from dovecot.conf (see preflight_check_dovecot_include).
# Virtual (non-system) mailboxes under a single shared vmail identity;
# passdb reads DovecotPasswdPath's own BLF-CRYPT hashes directly (rendered
# by the Go agent, see credentials.go); userdb is static since every
# virtual account shares the same uid/gid/home pattern. imap is TLS-only
# (imaps/993 only; the plaintext imap/143 listener is explicitly disabled,
# matching this capability's own manifest, which declares no port 143).
render_dovecot_conf() {
    cat <<DOVECOTCONF
# Managed entirely by LESta's mail installer
# (.install/services/mail/install.sh). Any hand edits are overwritten on
# the next --apply.

protocols = imap lmtp

mail_location = maildir:${VMAIL_HOME}/%d/%n
mail_uid = vmail
mail_gid = vmail

ssl = required
ssl_cert = <${ACME_CERTS_ROOT}/${MAIL_HOSTNAME}/fullchain.pem
ssl_key = <${ACME_CERTS_ROOT}/${MAIL_HOSTNAME}/privkey.pem

service imap-login {
  inet_listener imap {
    port = 0
  }
  inet_listener imaps {
    port = 993
    ssl = yes
  }
}

service lmtp {
  unix_listener lmtp {
    mode = 0666
  }
}

service auth {
  unix_listener auth-client {
    mode = 0660
    user = Debian-exim
  }
}

passdb {
  driver = passwd-file
  args = scheme=BLF-CRYPT ${DOVECOT_PASSWD_PATH}
}

userdb {
  driver = static
  args = uid=vmail gid=vmail home=${VMAIL_HOME}/%d/%n
}

protocol lmtp {
  mail_plugins = \$mail_plugins sieve
  plugin {
    sieve = ${SIEVE_DIR}/%d/%n.sieve
  }
}
DOVECOTCONF
}

# mail_health_probe <port> -> a plain TCP-connect probe, mirroring bind9's
# own bind9_health_probe exactly (nc, falling back to bash /dev/tcp, falling
# back to curl telnet:// as a last resort): the deep proof is the self-
# test's own real create/delete round trip, this is only the installer's
# own shallow structural probe.
mail_health_probe() {
    local port="$1"

    if command -v nc >/dev/null 2>&1; then
        nc -z -w 5 127.0.0.1 "${port}"
        return $?
    fi

    if command -v bash >/dev/null 2>&1; then
        bash -c "exec 3<>/dev/tcp/127.0.0.1/${port}" 2>/dev/null
        return $?
    fi

    if command -v curl >/dev/null 2>&1; then
        curl -fsS --max-time 5 -o /dev/null "telnet://127.0.0.1:${port}" 2>/dev/null
        return $?
    fi

    return 1
}

# install_mail_offline_bundle <bundle_dir>
# The --offline-bundle counterpart to the live 'apt-get install -y' calls
# below: verifies every vendored .deb first (fail closed, no mutation
# before this returns), then installs all six packages (exim4-daemon-heavy,
# dovecot-imapd/dovecot-lmtpd/dovecot-sieve, clamav-daemon/clamav-freshclam,
# spamassassin) in one dpkg -i, requiring no network access at all. Mirrors
# mariadb/install.sh's own install_mariadb_offline_bundle exactly, generation-
# retained under exim4-daemon-heavy's own version (the seed artifact), since
# every vendored package is installed and rolled back together as one unit.
install_mail_offline_bundle() {
    local bundle_dir="$1" out

    log_info "install_mail_offline_bundle: installing exim4-daemon-heavy/dovecot-imapd/dovecot-lmtpd/dovecot-sieve/clamav-daemon/clamav-freshclam/spamassassin/spamd from offline bundle ${bundle_dir} (no network access required)"

    offline_bundle_retain_generation mail "${bundle_dir}" exim4-daemon-heavy

    verify_offline_bundle_artifacts "${bundle_dir}"
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" verified "${bundle_dir}" "every vendored .deb in the offline bundle matched its ${BUNDLE_MANIFEST_FILENAME} sha256; proceeding to offline install"

    if ! out=$(install_offline_bundle_debs "${bundle_dir}"); then
        # A real package's own postinst script can itself try to start the
        # service, so a genuinely broken new generation can make dpkg -i
        # itself fail here, before this function's own later, separate
        # checks below ever run. offline_bundle_retain_generation above has
        # already retired the last-good generation to previous/, so a real
        # rollback is exactly as available and warranted here as it is for
        # a failure caught later (see nginx/install.sh's own identical
        # comment for the real CI failure that confirmed this).
        mail_fail_health "${EXIT_MUTATION_FAILURE}" dpkg_install_failed "${bundle_dir}" "$(printf '%s' "${out}" | tr '\n' ' ')"
    fi

    offline_bundle_snapshot_current mail "${bundle_dir}"
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" generation_retained "/var/lib/lesta/mail/bundle-generations" "offline-bundle generation bookkeeping updated: current/ now reflects this bundle; previous/ holds the prior generation if exim4-daemon-heavy's own version actually changed. Every vendored package is retained and rolled back together as one unit."
}

# discover_spamd_unit_name -> the real systemd unit name this node's own
# installed spamassassin package ships, since it varies by Ubuntu release
# (confirmed for real via CI: "spamassassin.service" does not exist on
# 24.04, unlike every other real daemon this installer manages by a fixed,
# known unit name). Empty output (never a hard failure here) means neither
# candidate exists yet -- callers decide what that means for them.
discover_spamd_unit_name() {
    systemctl list-unit-files --no-legend --full 'spamd.service' 'spamassassin.service' 2>/dev/null | awk '{print $1}' | head -n1
}

# mail_fail_health <exit_code> <error_code> <path> <message>
# Wraps every package-version-dependent failure site in install_mail below
# (systemctl enable/restart and health-probe failures for exim4, dovecot,
# clamav-daemon, and spamd): on the live path (OFFLINE_BUNDLE empty) this
# is byte-for-byte the same plain fail_step call these sites always made;
# only the --offline-bundle path additionally attempts an automatic
# rollback to the previous retained generation first. One shared bundle/
# generation underlies all four services here, so a rollback triggered by
# any one service's own health failure restarts every one of them, never
# just the one whose check failed -- mirroring mariadb_fail_health's own
# identical reasoning for its two systemd instances. spamd's own real unit
# name is discovered fresh here (never assumed): if package install itself
# is what failed and no such unit exists yet at all, it is simply left out
# of the restart list rather than aborting the whole rollback over an
# optional, not-yet-relevant unit.
mail_fail_health() {
    local units="exim4 dovecot clamav-daemon" spamd_unit

    spamd_unit=$(discover_spamd_unit_name)
    if [ -n "${spamd_unit}" ]; then
        units="${units} ${spamd_unit}"
    fi

    if [ -n "${OFFLINE_BUNDLE}" ]; then
        offline_bundle_fail_health_with_rollback "$1" "$2" "$3" "$4" mail "${units}"
    else
        fail_step "$1" "$2" "$3" "$4"
    fi
}

# clamd_health_probe -> a real ping over clamd's own local Unix socket
# (/run/clamav/clamd.ctl, Debian packaging's own default LocalSocket),
# speaking ClamAV's own real wire protocol (a raw "PING\n" command, real
# clamd answering "PONG\n") rather than a bare TCP-connect probe: the
# socket merely accepting a connection proves far less than clamd itself
# answering a real protocol round trip -- mirroring this file's own "the
# deep proof is a real round trip, the connect probe is only shallow"
# discipline (see mail_health_probe's own identical disclosure). Uses nc
# directly (already relied on elsewhere in this file) rather than a
# separate clamdscan client package, keeping this self-contained.
#
# Only ever called indirectly, by name, via wait_for_health_probe below
# (wait_for_health_probe clamd_health_probe 30) -- CI's own pinned lint
# tool version (0.9.0) cannot trace that dynamic dispatch and misreports
# this whole function (and spamd_health_probe below) as unreachable
# (SC2317/SC2329); a newer local copy of that same tool resolves it fine.
# shellcheck disable=SC2317,SC2329
clamd_health_probe() {
    command -v nc >/dev/null 2>&1 || return 1
    printf 'PING\n' | nc -U -w 5 "${CLAMD_SOCKET}" 2>/dev/null | grep -q 'PONG'
}

# spamd_health_probe -> a real ping to spamd over its own real TCP wire
# protocol (127.0.0.1:783, Debian packaging's own default), speaking
# SpamAssassin's own documented "PING" spamd-protocol command (real spamd
# answering "PONG") rather than a bare TCP-connect probe, for the identical
# reason clamd_health_probe speaks ClamAV's real protocol instead of using
# mail_health_probe's own generic connect-only check.
#
# See clamd_health_probe's own identical comment on why this needs an
# explicit shellcheck disable: only ever called indirectly, by name, via
# wait_for_health_probe.
# shellcheck disable=SC2317,SC2329
spamd_health_probe() {
    command -v nc >/dev/null 2>&1 || return 1
    printf 'PING SPAMC/1.5\r\n\r\n' | nc -w 5 127.0.0.1 "${SPAMD_PORT}" 2>/dev/null | grep -q 'PONG'
}

# wait_for_clamav_database -> polls up to 5 minutes for a real ClamAV virus
# database (main.cvd/main.cld, the file clamd itself hard-requires to start
# at all) to appear under /var/lib/clamav, the exact directory clamav-
# freshclam's own postinst downloads its first real signature set into. A
# genuinely fresh first-time download (main.cvd alone is well over 100MB)
# can take real, meaningful time even on a healthy connection, hence the
# generous bound; a disposable node with no real path to ClamAV's own CDN
# still times out here with a clear, attributable error rather than a
# confusing later clamd startup crash.
wait_for_clamav_database() {
    local waited=0

    while [ "${waited}" -lt 300 ]; do
        if [ -f /var/lib/clamav/main.cvd ] || [ -f /var/lib/clamav/main.cld ]; then
            return 0
        fi

        sleep 5
        waited=$((waited + 5))
    done

    return 1
}

# wait_for_health_probe <probe_function> <timeout_seconds> -> retries
# probe_function every 2 seconds until it succeeds or timeout_seconds
# elapses. clamd in particular can take real, meaningful time to load its
# own virus database into memory after systemctl restart returns, unlike
# exim4/dovecot (mail_health_probe's own single-shot 5-second connect
# timeout has always been sufficient for those two); a single immediate
# probe here would be a false negative on a genuinely healthy install, not
# a real failure.
wait_for_health_probe() {
    local probe_function="$1" timeout_seconds="$2" waited=0

    while [ "${waited}" -lt "${timeout_seconds}" ]; do
        if "${probe_function}"; then
            return 0
        fi

        sleep 2
        waited=$((waited + 2))
    done

    return 1
}

install_mail() {
    log_info "install_mail: installing exim4/dovecot and activating ${MAIL_SMTP_IMAP_CAPABILITY}"

    local out installed_version spamd_unit_name clamd_conf

    # --- vmail: the fixed, shared virtual-mailbox identity every hosted
    # account's own maildir is delivered under (see render_dovecot_conf's
    # own userdb static args). Not a real system login (nologin, no home
    # created by useradd itself: VMAIL_HOME is created explicitly below with
    # the right ownership) ------------------------------------------------
    if ! getent passwd vmail >/dev/null 2>&1; then
        useradd --system --no-create-home --shell /usr/sbin/nologin --home-dir "${VMAIL_HOME}" vmail \
            || fail_step "${EXIT_MUTATION_FAILURE}" useradd_failed /etc/passwd "failed to create system user vmail"
        add_change "${MAIL_SMTP_IMAP_CAPABILITY}" created /etc/passwd "created system user vmail"
    else
        add_change "${MAIL_SMTP_IMAP_CAPABILITY}" verified /etc/passwd "system user vmail already exists"
    fi

    install -d -m 0700 -o vmail -g vmail "${VMAIL_HOME}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${VMAIL_HOME}" "failed to create ${VMAIL_HOME}"
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" ensured "${VMAIL_HOME}" "virtual mailbox root present, mode 0700 vmail:vmail"

    # --- lesta.conf (Dovecot) is written BEFORE dovecot-imapd/dovecot-lmtpd/
    # dovecot-sieve are installed below, not after: dovecot.conf's own
    # !include ${LESTA_DOVECOT_CONF} line already exists at this point
    # (preflight_check_dovecot_include already required it), and installing
    # those packages triggers dovecot-core's own postinst to restart the
    # service immediately -- which re-parses dovecot.conf right then.
    # Dovecot's plain !include (unlike !include_try) hard-fails with "No
    # matches" if the target doesn't exist yet, exactly like bind9/
    # install.sh's own placeholder-fragment-before-package-install
    # precedent (see that file's own comment on the identical ordering
    # requirement for named.conf's include).
    install -d -m 0750 -o root -g lesta "${DOVECOT_LIVE_DIR}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${DOVECOT_LIVE_DIR}" "failed to create ${DOVECOT_LIVE_DIR}"
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" ensured "${DOVECOT_LIVE_DIR}" "directory present, mode 0750 root:lesta"

    mail_write_file "${LESTA_DOVECOT_CONF}" "$(render_dovecot_conf)" 0644
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" written "${LESTA_DOVECOT_CONF}" "complete Dovecot fragment written for hostname ${MAIL_HOSTNAME}, before dovecot-core's own package postinst can restart the service against it"

    # --- exim4-daemon-heavy (the "heavy" variant is required for DKIM
    # support; exim4-daemon-light is compiled without it),
    # dovecot-imapd/dovecot-lmtpd/dovecot-sieve, clamav-daemon/
    # clamav-freshclam (real virus scanning), and spamassassin (real spam
    # scoring) ------------------------------------------------------------
    if [ -n "${OFFLINE_BUNDLE}" ]; then
        install_mail_offline_bundle "${OFFLINE_BUNDLE}"
    else
        if ! out=$(apt-get install -y exim4-daemon-heavy 2>&1); then
            add_error apt_install_failed "$(printf '%s' "${out}" | tr '\n' ' ')" ""
            emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
        fi
        add_change "${MAIL_SMTP_IMAP_CAPABILITY}" installed "" "apt-get install -y exim4-daemon-heavy succeeded"

        if ! out=$(apt-get install -y dovecot-imapd dovecot-lmtpd dovecot-sieve 2>&1); then
            add_error apt_install_failed "$(printf '%s' "${out}" | tr '\n' ' ')" ""
            emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
        fi
        add_change "${MAIL_SMTP_IMAP_CAPABILITY}" installed "" "apt-get install -y dovecot-imapd dovecot-lmtpd dovecot-sieve succeeded"

        # spamd is a real, separate binary package on Ubuntu 24.04, built
        # from the same spamassassin source but version-locked to it as its
        # own dependency, not the other way around: it is not pulled in by
        # installing "spamassassin" alone, Depends or Recommends (confirmed
        # for real via packages.ubuntu.com and a real CI failure in the
        # --offline-bundle path, where excluding Recommends during its own
        # dependency-closure computation -- itself required for an
        # unrelated, already-fixed real bug -- left spamd out entirely).
        # Named explicitly here so both install paths always get it.
        if ! out=$(apt-get install -y clamav-daemon clamav-freshclam spamassassin spamd 2>&1); then
            add_error apt_install_failed "$(printf '%s' "${out}" | tr '\n' ' ')" ""
            emit_result_and_exit failed "${EXIT_MUTATION_FAILURE}"
        fi
        add_change "${MAIL_SMTP_IMAP_CAPABILITY}" installed "" "apt-get install -y clamav-daemon clamav-freshclam spamassassin spamd succeeded"
    fi

    installed_version=$(dpkg-query -W -f='${Version}' exim4-daemon-heavy 2>/dev/null || true)
    if [ -z "${installed_version}" ]; then
        fail_step "${EXIT_MUTATION_FAILURE}" apt_install_unverifiable "" "dpkg-query could not report an installed exim4-daemon-heavy version after install"
    fi
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" verified "" "dpkg-query reports exim4-daemon-heavy version ${installed_version}"

    installed_version=$(dpkg-query -W -f='${Version}' dovecot-core 2>/dev/null || true)
    if [ -z "${installed_version}" ]; then
        fail_step "${EXIT_MUTATION_FAILURE}" apt_install_unverifiable "" "dpkg-query could not report an installed dovecot-core version after install"
    fi
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" verified "" "dpkg-query reports dovecot-core version ${installed_version}"

    # --- force Exim into non-split mode: Debian's own sanctioned "I want
    # full manual control over exim4.conf" switch, required by this file's
    # own top comment (a second, separate ACL/router/transport/
    # authenticator file cannot be .include-d into Debian's own assembled
    # split config without a duplicate-section error) ------------------------
    if [ -f "${EXIM_UPDATE_CONF}" ]; then
        sed -i "s/^dc_use_split_config=.*/dc_use_split_config='false'/" "${EXIM_UPDATE_CONF}" \
            || fail_step "${EXIT_MUTATION_FAILURE}" exim_split_config_failed "${EXIM_UPDATE_CONF}" "failed to set dc_use_split_config='false' in ${EXIM_UPDATE_CONF}"
    fi
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" configured "${EXIM_UPDATE_CONF}" "dc_use_split_config set to false: this installer owns /etc/exim4/exim4.conf outright"

    # --- exim4.conf: written outright, not preserved -------------------------
    mail_write_file "${EXIM_CONF_PATH}" "$(render_exim_conf)" 0644
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" written "${EXIM_CONF_PATH}" "complete Exim config written for hostname ${MAIL_HOSTNAME}"

    if ! out=$(update-exim4.conf -v 2>&1); then
        fail_step "${EXIT_MUTATION_FAILURE}" exim_update_conf_failed "${EXIM_CONF_PATH}" "$(printf '%s' "${out}" | tr '\n' ' ')"
    fi
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" regenerated "/var/lib/exim4/config.autogenerated" "update-exim4.conf -v regenerated the live config from ${EXIM_CONF_PATH} (non-split mode: this is a direct reflection of it, not a separate assembly)"

    if ! out=$(exim4 -bV 2>&1); then
        mail_fail_health "${EXIT_HEALTH_FAILURE}" exim_config_invalid "${EXIM_CONF_PATH}" "exim4 -bV (validating the real, live-resolved config, no -C override) failed: $(printf '%s' "${out}" | tr '\n' ' ')"
    fi
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" validated "" "exim4 -bV passed against the real, live-resolved config"

    # Debian-exim (Exim's own real system identity) needs to read
    # EXIM_DATA_DIR's own lookup files (see acl_check_rcpt's own lsearch
    # references above) and DKIM_KEY_ROOT's own private keys (0640, see
    # dkim.go's own doc comment) to actually sign outgoing mail.
    usermod -aG lesta Debian-exim || fail_step "${EXIT_MUTATION_FAILURE}" usermod_failed "" "usermod -aG lesta Debian-exim failed"
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" group_membership_granted "" "Debian-exim added to the lesta group, so Exim can read its own lookup data and DKIM private keys"

    # Debian-exim also needs real access to clamd's own Unix socket
    # (CLAMD_SOCKET) to actually connect for av_scanner scanning: ClamAV's
    # own Debian packaging creates that socket group-owned by clamav, not
    # world-writable, the exact same "a non-owning system identity needs an
    # explicit group grant to use a real local socket" lesson already
    # learned for DKIM keys just above, applied proactively here rather
    # than waiting to rediscover it via a real connection-refused failure.
    usermod -aG clamav Debian-exim || fail_step "${EXIT_MUTATION_FAILURE}" usermod_failed "" "usermod -aG clamav Debian-exim failed"
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" group_membership_granted "" "Debian-exim added to the clamav group, so Exim can connect to clamd's own Unix socket"

    # The reverse direction matters just as much: over a Unix socket, clamd
    # is passed a spool file PATH to scan, not the message content itself
    # (confirmed for real via CI: without this, clamd hits a permission
    # error opening Exim's own spool file, and because the malware ACL
    # condition below uses /defer_ok, that scan error was silently treated
    # as "no virus found" rather than surfacing as a failure at all -- a
    # well-documented real Exim/ClamAV integration gap, not something
    # specific to this installer).
    usermod -aG Debian-exim clamav || fail_step "${EXIT_MUTATION_FAILURE}" usermod_failed "" "usermod -aG Debian-exim clamav failed"
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" group_membership_granted "" "clamav added to the Debian-exim group, so clamd can read the spool files Exim passes it by path for scanning"

    # StateRoot itself, explicitly, before any of its children: `install -d`
    # creates missing parents too, but only the leaf directory named on the
    # command line gets the requested mode/ownership -- an implicitly
    # created parent would otherwise inherit whatever the umask happens to
    # leave it with, exactly the kind of gap bind9/mariadb's own installers
    # avoid by creating every owned root explicitly.
    install -d -m 0750 -o root -g lesta "${MAIL_STATE_ROOT}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${MAIL_STATE_ROOT}" "failed to create ${MAIL_STATE_ROOT}"
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" ensured "${MAIL_STATE_ROOT}" "state root present, mode 0750 root:lesta"

    install -d -m 0750 -o root -g lesta "${EXIM_LIVE_DIR}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${EXIM_LIVE_DIR}" "failed to create ${EXIM_LIVE_DIR}"
    install -d -m 0750 -o root -g lesta "${EXIM_DATA_DIR}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${EXIM_DATA_DIR}" "failed to create ${EXIM_DATA_DIR}"
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" ensured "${EXIM_DATA_DIR}" "lookup-data directory present, mode 0750 root:lesta"

    install -d -m 0750 -o root -g lesta "${DKIM_KEY_ROOT}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${DKIM_KEY_ROOT}" "failed to create ${DKIM_KEY_ROOT}"
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" ensured "${DKIM_KEY_ROOT}" "DKIM key root present, mode 0750 root:lesta"

    install -d -m 0750 -o root -g lesta "${SIEVE_DIR}" || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed "${SIEVE_DIR}" "failed to create ${SIEVE_DIR}"
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" ensured "${SIEVE_DIR}" "sieve script root present, mode 0750 root:lesta"

    systemctl enable exim4 || mail_fail_health "${EXIT_HEALTH_FAILURE}" systemctl_enable_failed "" "systemctl enable exim4 failed"

    if ! out=$(systemctl restart exim4 2>&1); then
        mail_fail_health "${EXIT_HEALTH_FAILURE}" exim_restart_failed "" "$(printf '%s' "${out}" | tr '\n' ' ')"
    fi
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" enabled "" "systemctl enable exim4 + systemctl restart exim4 succeeded"

    mail_health_probe 25 || mail_fail_health "${EXIT_HEALTH_FAILURE}" exim_health_check_failed "" "exim4 did not answer a TCP health probe on 127.0.0.1:25 after restart"
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" healthy "" "TCP health probe against 127.0.0.1:25 succeeded"

    # lesta.conf (Dovecot) was already written above, before the dovecot
    # packages were installed (see this function's own comment on why).

    if ! out=$(doveconf -n 2>&1 >/dev/null); then
        mail_fail_health "${EXIT_HEALTH_FAILURE}" dovecot_config_invalid "${LESTA_DOVECOT_CONF}" "doveconf -n reported errors: $(printf '%s' "${out}" | tr '\n' ' ')"
    fi
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" validated "" "doveconf -n passed against the real, live-resolved config"

    systemctl enable dovecot || mail_fail_health "${EXIT_HEALTH_FAILURE}" systemctl_enable_failed "" "systemctl enable dovecot failed"

    if ! out=$(systemctl restart dovecot 2>&1); then
        mail_fail_health "${EXIT_HEALTH_FAILURE}" dovecot_restart_failed "" "$(printf '%s' "${out}" | tr '\n' ' ')"
    fi
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" enabled "" "systemctl enable dovecot + systemctl restart dovecot succeeded"

    mail_health_probe 993 || mail_fail_health "${EXIT_HEALTH_FAILURE}" dovecot_health_check_failed "" "dovecot did not answer a TCP health probe on 127.0.0.1:993 after restart"
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" healthy "" "TCP health probe against 127.0.0.1:993 succeeded"

    # --- clamav-daemon: clamd will not start at all without a real virus
    # database already present under /var/lib/clamav (main.cvd/main.cld,
    # daily.cvd/daily.cld) -- clamav-freshclam's own postinst already
    # attempts a first real signature fetch synchronously, but this waits
    # and retries explicitly rather than trusting that timing blindly,
    # mirroring this file's own repeated "verify the real dependency is
    # ready before starting what depends on it" discipline (the same
    # discipline behind lesta.conf's own before-package-install ordering
    # above). A disposable node with no real network path to ClamAV's own
    # CDN will fail here loudly, not with a confusing later clamd crash.
    wait_for_clamav_database || mail_fail_health "${EXIT_HEALTH_FAILURE}" clamav_database_missing "/var/lib/clamav" "no real ClamAV virus database (main.cvd/main.cld) appeared after waiting for clamav-freshclam's own first signature fetch"
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" verified "/var/lib/clamav" "a real ClamAV virus database is present"

    # clamd drops every one of its own supplementary group memberships at
    # startup by default, a real ClamAV hardening feature -- so the
    # usermod -aG Debian-exim clamav grant above would otherwise never
    # actually take effect inside the running clamd process itself.
    clamd_conf=/etc/clamav/clamd.conf
    if grep -qE '^#?AllowSupplementaryGroups' "${clamd_conf}" 2>/dev/null; then
        sed -i 's/^#\?AllowSupplementaryGroups.*/AllowSupplementaryGroups true/' "${clamd_conf}" \
            || fail_step "${EXIT_MUTATION_FAILURE}" clamd_conf_write_failed "${clamd_conf}" "failed to set AllowSupplementaryGroups true in ${clamd_conf}"
    else
        printf '\nAllowSupplementaryGroups true\n' >> "${clamd_conf}" \
            || fail_step "${EXIT_MUTATION_FAILURE}" clamd_conf_write_failed "${clamd_conf}" "failed to append AllowSupplementaryGroups true to ${clamd_conf}"
    fi
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" configured "${clamd_conf}" "AllowSupplementaryGroups true set: clamd would otherwise drop its own Debian-exim group membership at startup and be unable to read Exim's spool files it is asked to scan"

    systemctl enable clamav-daemon || mail_fail_health "${EXIT_HEALTH_FAILURE}" systemctl_enable_failed "" "systemctl enable clamav-daemon failed"

    if ! out=$(systemctl restart clamav-daemon 2>&1); then
        mail_fail_health "${EXIT_HEALTH_FAILURE}" clamav_daemon_restart_failed "" "$(printf '%s' "${out}" | tr '\n' ' ')"
    fi
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" enabled "" "systemctl enable clamav-daemon + systemctl restart clamav-daemon succeeded"

    wait_for_health_probe clamd_health_probe 30 \
        || mail_fail_health "${EXIT_HEALTH_FAILURE}" clamav_daemon_health_check_failed "${CLAMD_SOCKET}" "clamd did not answer a real PING/PONG protocol probe over ${CLAMD_SOCKET} after restart"
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" healthy "" "real PING/PONG protocol probe against clamd's own socket succeeded"

    # --- spamassassin: the spamd service Debian's own packaging ships is
    # disabled by default (ENABLED=0 in /etc/default/spamassassin) until an
    # operator explicitly opts in -- flip it here, the same "package
    # installs, service starts disabled until explicitly enabled" pattern
    # already confirmed real for Dovecot's own postinst behavior elsewhere
    # in this file, just gated by a config flag instead of an include line.
    if [ -f /etc/default/spamassassin ]; then
        sed -i 's/^ENABLED=.*/ENABLED=1/' /etc/default/spamassassin \
            || fail_step "${EXIT_MUTATION_FAILURE}" spamassassin_enable_flag_failed /etc/default/spamassassin "failed to set ENABLED=1 in /etc/default/spamassassin"
    fi
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" configured /etc/default/spamassassin "ENABLED=1 set: spamd would otherwise stay disabled per Debian's own packaging default"

    # The real systemd unit name this package ships varies by Ubuntu release
    # (confirmed for real via CI: "spamassassin.service" does not exist on
    # 24.04, unlike every other real daemon this installer manages by a
    # fixed, known unit name) -- discovered at runtime from the real
    # installed unit list rather than hardcoding a single guessed name a
    # second time.
    spamd_unit_name=$(discover_spamd_unit_name)
    if [ -z "${spamd_unit_name}" ]; then
        mail_fail_health "${EXIT_HEALTH_FAILURE}" spamassassin_unit_not_found "" "neither spamd.service nor spamassassin.service exists after installing the spamassassin package"
    fi
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" verified "" "real systemd unit for spamd is ${spamd_unit_name}"

    systemctl enable "${spamd_unit_name}" || mail_fail_health "${EXIT_HEALTH_FAILURE}" systemctl_enable_failed "" "systemctl enable ${spamd_unit_name} failed"

    if ! out=$(systemctl restart "${spamd_unit_name}" 2>&1); then
        mail_fail_health "${EXIT_HEALTH_FAILURE}" spamassassin_restart_failed "" "$(printf '%s' "${out}" | tr '\n' ' ')"
    fi
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" enabled "" "systemctl enable ${spamd_unit_name} + systemctl restart ${spamd_unit_name} succeeded"

    wait_for_health_probe spamd_health_probe 30 \
        || mail_fail_health "${EXIT_HEALTH_FAILURE}" spamd_health_check_failed "" "spamd did not answer a real PING/PONG protocol probe on 127.0.0.1:${SPAMD_PORT} after restart"
    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" healthy "" "real PING/PONG protocol probe against spamd succeeded"

    checkpoint_write install_mail "${MANIFEST_DIGEST}"
    log_info "install_mail complete"
}

# --- phase 4: bootstrap_node_health --------------------------------------
#
# selftest_new_uuid/selftest_envelope/selftest_invoke_agent/
# selftest_status_from_output/run_node_health_selftest_delete live in
# lib/selftest.sh, shared with every other leaf-service installer's own
# self-test. mail.smtp-imap.v1's own Payload is one fixed shape for every
# operation (see agent/internal/capability/mail/payload.go's own doc
# comment), so unlike backup.encrypted-artifacts.v1's own self-test, this
# one reuses the shared run_node_health_selftest_delete helper directly.

run_node_health_selftest() {
    local resource_id create_idem create_corr delete_idem delete_corr password payload envelope agent_out agent_status status_line

    resource_id=$(selftest_new_uuid)
    create_idem=$(selftest_new_uuid)
    create_corr=$(selftest_new_uuid)
    delete_idem=$(selftest_new_uuid)
    delete_corr=$(selftest_new_uuid)

    # 48 lowercase hex characters, matching passwordPattern exactly (see
    # agent/internal/capability/mail/payload.go).
    password=$(od -An -tx1 -N24 /dev/urandom | tr -d ' \n')

    payload=$(json_join_object \
        "$(json_kv_str "domain" "selftest.lesta.invalid")" \
        "$(json_kv_raw "antivirus_enabled" "false")" \
        "$(json_kv_raw "antispam_enabled" "false")" \
        "$(json_kv_raw "dkim_enabled" "false")" \
        "$(json_kv_raw "accounts" "$(json_join_array "$(json_join_object \
            "$(json_kv_str "local_part" "selftest")" \
            "$(json_kv_str "password" "${password}")" \
            "$(json_kv_raw "forward_only" "false")" \
            "$(json_kv_raw "autoreply_enabled" "false")" \
            "$(json_kv_raw "suspended" "false")")")")" \
        "$(json_kv_raw "suspended" "false")")

    envelope=$(selftest_envelope "${MAIL_SMTP_IMAP_CAPABILITY}" create "${resource_id}" "${create_idem}" "${create_corr}" 1 "${payload}")

    agent_status=0
    agent_out=$(selftest_invoke_agent "${envelope}") || agent_status=$?

    if [ "${agent_status}" -ne 0 ]; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_create_failed "${AGENT_BINARY_DEST}" "agent exited ${agent_status}: $(printf '%s' "${agent_out}" | tr '\n' ' ')"
    fi

    status_line=$(selftest_status_from_output "${agent_out}")
    if [ "${status_line}" != "applied" ]; then
        run_node_health_selftest_delete "${MAIL_SMTP_IMAP_CAPABILITY}" "${resource_id}" "${payload}" "${delete_idem}" "${delete_corr}" || true
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_create_not_applied "${AGENT_BINARY_DEST}" "agent returned status=${status_line:-unknown} for create, expected applied: $(printf '%s' "${agent_out}" | tr '\n' ' ')"
    fi

    log_info "bootstrap_node_health self-test: create returned status=applied"

    if ! run_node_health_selftest_delete "${MAIL_SMTP_IMAP_CAPABILITY}" "${resource_id}" "${payload}" "${delete_idem}" "${delete_corr}"; then
        agent_fail_selftest_with_rollback "${EXIT_HEALTH_FAILURE}" selftest_cleanup_failed "${EXIM_DATA_DIR}" "self-test create succeeded but the throwaway resource could not be deleted afterward"
    fi

    add_change "${MAIL_SMTP_IMAP_CAPABILITY}" installed_structural_only "${AGENT_BINARY_DEST}" "self-test create-then-delete of a throwaway mail domain (selftest.lesta.invalid) against the real, just-installed exim/dovecot returned status=applied both times; remote control-plane registration is not yet built, so ${MAIL_SMTP_IMAP_CAPABILITY} is structurally installed and health-checked but NOT YET control-plane-registered"
    log_info "bootstrap_node_health self-test: create+delete both returned status=applied"
}

bootstrap_node_health() {
    log_info "bootstrap_node_health: installing agent binary and running disposable self-test"

    agent_install_binary "${AGENT_BINARY_SRC}" "${NODE_HEALTH_MANIFEST}"

    run_node_health_selftest

    checkpoint_write bootstrap_node_health "${MANIFEST_DIGEST}"
    log_info "bootstrap_node_health complete"
}

# --- main -------------------------------------------------------------

main() {
    MANIFEST_DIGEST=$(compute_manifest_digest "${BASE_MANIFEST}" "${FIREWALL_MANIFEST}" "${NODE_HEALTH_MANIFEST}" "${MAIL_MANIFEST}")

    parse_args "$@"
    validate_args

    if [ "${MODE}" = "version" ]; then
        emit_version_and_exit
    fi

    RUN_ID=$(run_generate_id)
    run_install_cleanup_trap

    log_info "starting install.sh mode=${MODE} run_id=${RUN_ID} installer_version=${SCRIPT_VERSION}"

    # --prepare-config is dispatched here, AFTER RUN_ID/the cleanup trap are
    # set up but BEFORE run_preflight: the normal preflight's own
    # preflight_check_dovecot_include requires the include line to already
    # be present, matching bind9/install.sh's own identical ordering
    # rationale.
    if [ "${MODE}" = "prepare-config" ]; then
        run_prepare_config
    fi

    # No mutation happens before this point, matching every other
    # installer's own ordering: log_init itself would create a directory,
    # and ensure_lesta_group would run groupadd, so both wait until after
    # run_preflight has passed.
    run_preflight

    if [ "${MODE}" = "dry-run" ]; then
        emit_dry_run_result_and_exit
    fi

    # --apply, and preflight passed: only now is any mutation permitted.
    ensure_lesta_group
    log_init
    log_info "preflight passed; beginning apply mutations"

    bootstrap_base
    bootstrap_firewall_baseline mail "${MAIL_MANIFEST}"
    checkpoint_write bootstrap_firewall_baseline "${MANIFEST_DIGEST}"
    install_mail
    bootstrap_node_health

    checkpoint_remove
    release_write "${RELEASE_ID}" "${MANIFEST_DIGEST}"

    emit_apply_success_and_exit
}

main "$@"
