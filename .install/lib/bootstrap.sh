# shellcheck shell=sh
# shellcheck disable=SC3043
#
# .install/lib/bootstrap.sh
#
# Phase 1 of every leaf-service installer: the lesta group, the lesta-agent user and the three
# base directories every service shares. Capability-agnostic, so one copy serves them all; a
# service that needs more at this point (php-fpm's self-test group) adds its own step after
# bootstrap_base in its main().
#
# Also the PHP package repository pin (ensure_sury_php_repo, retire_ondrej_php_ppa), which
# php-fpm and every installer that runs apt-get update share.

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

    install -d -m 0751 -o root -g lesta /etc/lesta || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /etc/lesta "failed to create /etc/lesta"
    install -d -m 0751 -o root -g lesta /var/lib/lesta || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/lib/lesta "failed to create /var/lib/lesta"
    install -d -m 0751 -o root -g lesta /var/log/lesta || fail_step "${EXIT_MUTATION_FAILURE}" mkdir_failed /var/log/lesta "failed to create /var/log/lesta"
    add_change base.os.v1 ensured /etc/lesta "directory present, mode 0751 root:lesta"
    add_change base.layout.v1 ensured /var/lib/lesta "directory present, mode 0750 root:lesta"
    add_change base.layout.v1 ensured /var/log/lesta "directory present, mode 0751 root:lesta (nginx workers pass through to reopen their logs)"

    update-ca-certificates >/dev/null 2>&1 || true
    add_change base.tls.v1 refreshed /etc/ssl/certs "update-ca-certificates run"
    bootstrap_trim_os_services

    checkpoint_write bootstrap_base "${MANIFEST_DIGEST}"
    log_info "bootstrap_base complete"
}

# bootstrap_trim_os_services stops Ubuntu services a hosting node never uses, to keep its memory
# small (vault: "Minimal memory footprint"): ModemManager (cellular modems), udisks2 (desktop disk
# management), multipathd (SAN multipath, unless the node has multipath devices) and
# networkd-dispatcher (network event hooks, unless the node has hooks of its own), and fwupd's
# daily metadata refresh timer, which is what starts fwupd (fwupd itself exits after five idle
# minutes, so it is left alone). Each is disabled and stopped, never removed:
# no package is touched, `systemctl enable --now <unit>` brings one back, and `fwupdmgr` still
# works by hand. Left alone: unattended-upgrades (a security function) and snapd, which something
# at boot starts again through its socket even when disabled, so only removing the package would
# keep it off, and LESta does not remove OS packages it does not own. Only a unit that is enabled or running is changed, so a re-run records nothing new.
bootstrap_trim_os_services() {
    local unit

    for unit in ModemManager.service udisks2.service fwupd-refresh.timer; do
        bootstrap_stop_unit "${unit}" || true
    done

    # multipathd.socket is static (it cannot be disabled, and udev may open it again during the same
    # boot); with the service disabled it is not started at boot, so it is only stopped alongside.
    if ! dmsetup ls --target multipath 2>/dev/null | grep -qv '^No devices found'; then
        if bootstrap_stop_unit multipathd.service; then
            systemctl stop multipathd.socket >/dev/null 2>&1 || true
        fi
    fi

    if ! find /etc/networkd-dispatcher -type f 2>/dev/null | grep -q .; then
        bootstrap_stop_unit networkd-dispatcher.service || true
    fi
}

# bootstrap_stop_unit <unit> disables and stops one unit when it is enabled or active (return 0); a
# unit the node does not have, or that is already off, is left as it is (return 1).
bootstrap_stop_unit() {
    local unit="$1"

    systemctl cat "${unit}" >/dev/null 2>&1 || return 1

    if systemctl is-enabled --quiet "${unit}" 2>/dev/null || systemctl is-active --quiet "${unit}" 2>/dev/null; then
        systemctl disable --now "${unit}" >/dev/null 2>&1 || systemctl stop "${unit}" >/dev/null 2>&1 || true
        add_change base.os.v1 disabled "${unit}" "${unit} disabled and stopped: a hosting node does not use it (systemctl enable --now ${unit} brings it back)"
        return 0
    fi

    return 1
}

# --- PHP package repository ------------------------------------------------------------------
#
# Ondřej Surý's PHP packages for Ubuntu (8.1 to 8.5, beside Ubuntu's own single version) are
# published at packages.sury.org/php. His Launchpad PPA ppa:ondrej/php, which LESta pinned until
# 2026-11-24, was frozen around 2026-11-20 and its Release Label renamed to "PPA for PHP
# (deprecated)", which makes every apt-get update on a node still listing it fail. The repository
# is pinned the way mariadb/install.sh pins MariaDB's: a downloaded keyring that must hold exactly
# SURY_PHP_KEY_FINGERPRINT (DEB.SURY.ORG Automatic Signing Key, expires 2028-02-04, so a rotated
# key fails closed here until this constant follows it), trusted for that one source only
# (signed-by, outside trusted.gpg.d), and a hand-written sources file.
SURY_PHP_KEYRING="/etc/apt/keyrings/lesta-sury-php.gpg"
SURY_PHP_KEY_FINGERPRINT="15058500A0235D97F5D10063B188E2B695BD4743"
SURY_PHP_SOURCES_LIST="/etc/apt/sources.list.d/lesta-sury-php.list"
ONDREJ_PPA_KEYRINGS="/etc/apt/keyrings/lesta-ondrej-php.gpg /etc/apt/trusted.gpg.d/ondrej-php-keyring.gpg"
# Set to 1 when ensure_sury_php_repo or retire_ondrej_php_ppa changed an apt source, so the caller
# knows its package lists need an apt-get update.
# shellcheck disable=SC2034 # read by php-fpm/install.sh
PHP_APT_SOURCES_CHANGED=0

# ensure_sury_php_repo <capability> pins packages.sury.org/php for this node's Ubuntu codename.
# Idempotent: LESta's own pin, or one the base image already carries under another file (a second
# entry for the same URL with a different signed-by makes apt fail), is kept as it is. Returns 1
# after add_error when the key cannot be fetched or is not exactly the pinned one; the caller
# emits the result.
ensure_sury_php_repo() {
    local capability="$1" out codename existing

    existing=$(grep -rl 'packages\.sury\.org/php' /etc/apt/sources.list.d/ 2>/dev/null | head -1 || true)
    if [ -n "${existing}" ]; then
        add_change "${capability}" verified "${existing}" "packages.sury.org/php already pinned"

        return 0
    fi

    log_info "ensure_sury_php_repo: pinning packages.sury.org/php (key fingerprint ${SURY_PHP_KEY_FINGERPRINT})"

    install -d -m 0755 /etc/apt/keyrings
    if ! out=$(curl -fsSL --proto '=https' -o "${SURY_PHP_KEYRING}.tmp" https://packages.sury.org/php/apt.gpg 2>&1); then
        rm -f "${SURY_PHP_KEYRING}.tmp"
        add_error sury_php_keyring_fetch_failed "$(printf '%s' "${out}" | tr '\n' ' ')" "${SURY_PHP_KEYRING}"

        return 1
    fi
    if ! verify_keyring_fingerprints "${SURY_PHP_KEYRING}.tmp" "${SURY_PHP_KEY_FINGERPRINT}"; then
        rm -f "${SURY_PHP_KEYRING}.tmp"
        add_error sury_php_keyring_unexpected "the downloaded key is not exactly ${SURY_PHP_KEY_FINGERPRINT}; refusing to trust it" "${SURY_PHP_KEYRING}"

        return 1
    fi
    mv -f "${SURY_PHP_KEYRING}.tmp" "${SURY_PHP_KEYRING}"
    chmod 0644 "${SURY_PHP_KEYRING}"
    add_change "${capability}" installed "${SURY_PHP_KEYRING}" "packages.sury.org signing key (fingerprint ${SURY_PHP_KEY_FINGERPRINT}) downloaded, checked, and trusted for that repository only"

    codename=$(preflight_os_release_field VERSION_CODENAME)
    cat > "${SURY_PHP_SOURCES_LIST}.tmp" <<SOURCES
# packages.sury.org/php: the multi-version PHP-FPM packages web.php-fpm.v1 installs beside
# Ubuntu's own version. Managed by LESta; do not edit by hand.
deb [arch=amd64 signed-by=${SURY_PHP_KEYRING}] https://packages.sury.org/php/ ${codename} main
SOURCES
    mv -f "${SURY_PHP_SOURCES_LIST}.tmp" "${SURY_PHP_SOURCES_LIST}"
    add_change "${capability}" installed "${SURY_PHP_SOURCES_LIST}" "apt source pinned to packages.sury.org/php (codename ${codename})"
    # shellcheck disable=SC2034 # read by php-fpm/install.sh
    PHP_APT_SOURCES_CHANGED=1
}

# retire_ondrej_php_ppa <capability> runs before an installer's apt-get update: when any apt
# source still lists the frozen ondrej/php PPA (LESta's own ondrej-php.list from before
# 2026-11-24, or a base image's own entry), it pins packages.sury.org/php in its place and removes
# the PPA source and LESta's old keyring. That is Ondřej's own migration: no installed package is
# removed or downgraded, and their next updates come from sury. On 26.04, where PHP comes from
# Ubuntu's own archive, the PPA source is only removed. Returns 1 after add_error, like
# ensure_sury_php_repo.
retire_ondrej_php_ppa() {
    local capability="$1" sources file keyring

    sources=$(grep -rl 'ppa\.launchpadcontent\.net/ondrej/php' /etc/apt/sources.list.d/ 2>/dev/null || true)
    [ -n "${sources}" ] || return 0

    if [ "$(preflight_os_release_field VERSION_ID)" != "26.04" ]; then
        ensure_sury_php_repo "${capability}" || return 1
    fi

    for file in ${sources}; do
        rm -f "${file}"
        add_change "${capability}" removed "${file}" "ondrej/php PPA source removed: the PPA is frozen and its renamed Release Label makes apt-get update fail; PHP updates come from packages.sury.org/php"
    done
    for keyring in ${ONDREJ_PPA_KEYRINGS}; do
        if [ -e "${keyring}" ]; then
            rm -f "${keyring}"
            add_change "${capability}" removed "${keyring}" "the ondrej/php PPA's keyring, no longer used by any source"
        fi
    done
    # shellcheck disable=SC2034 # read by php-fpm/install.sh
    PHP_APT_SOURCES_CHANGED=1
}
