#!/bin/sh
# shellcheck shell=sh
# dash (the only /bin/sh on Ubuntu 24.04/26.04) supports local; the single
# file-wide directive below (it appears before this file's first command)
# suppresses SC3043 for every `local` use in this file.
# shellcheck disable=SC3043
#
# install-cp.sh (repository root)
#
# Guides an operator through setting up the LESta control-plane application
# itself (the Laravel/Inertia app in this repository) -- a different thing
# from install.sh, which fetches .install/ onto a hosting NODE. This script
# assumes it is being run from inside a real, full checkout of this
# repository (composer.json, artisan, app/ all present) -- the control-plane
# app needs essentially all of it, unlike a node, so there is no sparse-fetch
# step here to guide through.
#
# Scope, deliberately: this script only ever touches files inside this
# checkout (.env) and runs composer/npm/artisan against them. It never
# touches system-wide web server, systemd, or crontab configuration --
# those are printed as copy-pasteable guidance at the end instead, the same
# "never silently mutate operator-owned system config" boundary
# .install/services/nginx (etc.) already draws around nginx.conf itself.
#
# If this box also ran .install/services/mariadb/install.sh (the ADR's own
# default single-node deployment: control-plane and tenant database share
# one physical node), its real, generated DB_* credentials at
# /etc/lesta/mariadb-control-plane-app.env are picked up automatically
# instead of asking you to hand-merge them, per that installer's own
# printed instruction.
set -eu

SCRIPT_VERSION="1.0.0"
CONTROL_PLANE_CREDENTIALS_FILE="/etc/lesta/mariadb-control-plane-app.env"

EXIT_OK=0
EXIT_INVALID_INVOCATION=10
EXIT_UNSUPPORTED_PLATFORM=11
EXIT_MUTATION_FAILURE=20

ASSUME_YES=0
SEED=""
ENV_FILE=".env"

usage() {
    cat <<USAGE >&2
Usage: install-cp.sh [--yes] [--seed|--no-seed] [--help] [--version]

Sets up the LESta control-plane application in this checkout: .env,
APP_KEY, composer/npm dependencies, a production asset build, and
database migrations. Run from the repository root.

  --yes        Non-interactive: accept every default / detected value
               without prompting (for scripted or repeat installs).
  --seed       Run the database seeders after migrating (fresh install).
  --no-seed    Skip seeding (upgrading an existing install).
               Without either, you are asked, unless --yes is given (in
               which case seeding is skipped, the safe default for a
               re-run against an existing database).
  --help       Print this message.
  --version    Print this script's own version and exit.

This never touches system-wide web server, systemd, or crontab
configuration -- it prints copy-pasteable guidance for those at the end
instead.
USAGE
}

fail_invocation() {
    printf 'install-cp.sh: %s\n' "$1" >&2
    usage
    exit "${EXIT_INVALID_INVOCATION}"
}

fail_mutation() {
    printf 'install-cp.sh: %s\n' "$1" >&2
    exit "${EXIT_MUTATION_FAILURE}"
}

parse_args() {
    while [ "$#" -gt 0 ]; do
        case "$1" in
            --yes)
                ASSUME_YES=1
                ;;
            --seed)
                SEED="yes"
                ;;
            --no-seed)
                SEED="no"
                ;;
            --help)
                usage
                exit "${EXIT_OK}"
                ;;
            --version)
                printf 'install-cp.sh %s\n' "${SCRIPT_VERSION}"
                exit "${EXIT_OK}"
                ;;
            *)
                fail_invocation "unrecognized argument: $1"
                ;;
        esac
        shift
    done
}

require_checkout() {
    if [ ! -f "artisan" ] || [ ! -f "composer.json" ]; then
        fail_invocation "run this from the root of a full LESta checkout (artisan and composer.json not found here) -- not the sparse .install-only checkout install.sh creates"
    fi
}

require_command() {
    command -v "$1" >/dev/null 2>&1 || {
        printf 'install-cp.sh: %s is required and was not found on PATH.\n' "$1" >&2
        exit "${EXIT_UNSUPPORTED_PLATFORM}"
    }
}

require_php_version() {
    php_version=$(php -r 'echo PHP_VERSION;')
    php_major=$(printf '%s' "${php_version}" | cut -d. -f1)
    php_minor=$(printf '%s' "${php_version}" | cut -d. -f2)

    # composer.json's own real constraint ("php": "^8.3"), not the 8.4 this
    # project otherwise develops against -- checked here, not assumed.
    if [ "${php_major}" -lt 8 ] || { [ "${php_major}" -eq 8 ] && [ "${php_minor}" -lt 3 ]; }; then
        printf 'install-cp.sh: PHP %s found, but composer.json requires ^8.3.\n' "${php_version}" >&2
        exit "${EXIT_UNSUPPORTED_PLATFORM}"
    fi
}

preflight() {
    require_checkout
    require_command php
    require_command composer
    require_command node
    require_command npm
    require_php_version
}

# ask <prompt> <default> -> prints the answer (default kept on Enter, or
# always the default when --yes was given, so this same function serves both
# the interactive and non-interactive paths without a second code path).
ask() {
    prompt="$1"
    default="$2"

    if [ "${ASSUME_YES}" -eq 1 ]; then
        printf '%s\n' "${default}"
        return
    fi

    printf '%s [%s]: ' "${prompt}" "${default}" >&2
    read -r answer
    if [ -z "${answer}" ]; then
        printf '%s\n' "${default}"
    else
        printf '%s\n' "${answer}"
    fi
}

# current_env_value <key> <fallback> -> the key's current value in
# ${ENV_FILE} if it's already set to something real, else <fallback> --
# lets every prompt default to "keep what's already there" on a re-run
# instead of forgetting it.
current_env_value() {
    key="$1"
    fallback="$2"
    existing=$(grep "^${key}=" "${ENV_FILE}" 2>/dev/null | head -n1 | cut -d= -f2- || true)
    if [ -n "${existing}" ]; then
        printf '%s\n' "${existing}"
    else
        printf '%s\n' "${fallback}"
    fi
}

# set_env_value <key> <value> -> replaces KEY=... in ${ENV_FILE} (or appends
# it if absent). Uses awk with the key/value passed through the environment,
# never interpolated into the awk program text or a sed pattern, so a value
# containing /, &, or other pattern-special characters (a hand-typed DB
# password, say) can never corrupt the substitution.
set_env_value() {
    key="$1"
    value="$2"

    env_key="${key}" env_value="${value}" awk '
        BEGIN { done = 0; k = ENVIRON["env_key"]; v = ENVIRON["env_value"] }
        index($0, k "=") == 1 { print k "=" v; done = 1; next }
        { print }
        END { if (!done) print k "=" v }
    ' "${ENV_FILE}" > "${ENV_FILE}.tmp" || fail_mutation "failed to update ${key} in ${ENV_FILE}"
    mv "${ENV_FILE}.tmp" "${ENV_FILE}" || fail_mutation "failed to write ${ENV_FILE}"
}

configure_env_file() {
    if [ ! -f "${ENV_FILE}" ]; then
        [ -f ".env.example" ] || fail_mutation ".env.example not found -- cannot create ${ENV_FILE}"
        cp ".env.example" "${ENV_FILE}"
        printf 'install-cp.sh: created %s from .env.example\n' "${ENV_FILE}"
    fi

    app_url=$(ask "Application URL (APP_URL)" "$(current_env_value APP_URL http://localhost)")
    set_env_value APP_URL "${app_url}"
    set_env_value APP_ENV "production"
    set_env_value APP_DEBUG "false"

    if [ -r "${CONTROL_PLANE_CREDENTIALS_FILE}" ]; then
        printf 'install-cp.sh: found %s (written by .install/services/mariadb/install.sh on this node) -- using its real, generated DB credentials instead of asking.\n' "${CONTROL_PLANE_CREDENTIALS_FILE}"
        # Shaped as plain KEY=VALUE lines by that installer's own write step
        # (DB_CONNECTION/DB_HOST/DB_PORT/DB_DATABASE/DB_USERNAME/DB_PASSWORD)
        # -- read here the same restricted way, never sourced as a script.
        while IFS='=' read -r cred_key cred_value; do
            case "${cred_key}" in
                DB_CONNECTION | DB_HOST | DB_PORT | DB_DATABASE | DB_USERNAME | DB_PASSWORD)
                    set_env_value "${cred_key}" "${cred_value}"
                    ;;
            esac
        done <"${CONTROL_PLANE_CREDENTIALS_FILE}"
    else
        printf 'install-cp.sh: %s not found -- this node did not run the MariaDB node installer, or the control-plane database lives elsewhere. Enter its connection details:\n' "${CONTROL_PLANE_CREDENTIALS_FILE}" >&2
        db_host=$(ask "Database host (DB_HOST)" "$(current_env_value DB_HOST 127.0.0.1)")
        db_port=$(ask "Database port (DB_PORT)" "$(current_env_value DB_PORT 3306)")
        db_database=$(ask "Database name (DB_DATABASE)" "$(current_env_value DB_DATABASE lesta)")
        db_username=$(ask "Database username (DB_USERNAME)" "$(current_env_value DB_USERNAME lesta)")
        db_password=$(ask "Database password (DB_PASSWORD)" "$(current_env_value DB_PASSWORD '')")
        set_env_value DB_CONNECTION mariadb
        set_env_value DB_HOST "${db_host}"
        set_env_value DB_PORT "${db_port}"
        set_env_value DB_DATABASE "${db_database}"
        set_env_value DB_USERNAME "${db_username}"
        set_env_value DB_PASSWORD "${db_password}"
    fi
}

install_dependencies() {
    printf 'install-cp.sh: composer install --no-dev --optimize-autoloader\n'
    composer install --no-dev --optimize-autoloader || fail_mutation "composer install failed"

    printf 'install-cp.sh: npm ci && npm run build\n'
    npm ci || fail_mutation "npm ci failed"
    npm run build || fail_mutation "npm run build failed"
}

ensure_app_key() {
    if ! grep -q '^APP_KEY=base64:' "${ENV_FILE}" 2>/dev/null; then
        printf 'install-cp.sh: generating APP_KEY\n'
        php artisan key:generate --force || fail_mutation "php artisan key:generate failed"
    fi
}

run_migrations() {
    do_seed="${SEED}"
    if [ -z "${do_seed}" ]; then
        if [ "${ASSUME_YES}" -eq 1 ]; then
            do_seed="no"
        else
            do_seed=$(ask "Fresh install -- seed default roles/packages too? (yes/no)" "yes")
        fi
    fi

    printf 'install-cp.sh: php artisan migrate --force\n'
    php artisan migrate --force || fail_mutation "php artisan migrate failed"

    if [ "${do_seed}" = "yes" ]; then
        printf 'install-cp.sh: php artisan db:seed --force\n'
        php artisan db:seed --force || fail_mutation "php artisan db:seed failed"
    fi
}

print_next_steps() {
    app_dir=$(pwd)

    cat <<NEXT

install-cp.sh: done. The control-plane application is installed and its database is migrated.

Three things this script deliberately does not touch -- they depend on your
own web server, init system, and existing configuration, so here is what to
add rather than an automatic change to files it does not own:

1. Web server (nginx + php-fpm example; document root MUST be public/, never the repo root):

     server {
         listen 80;
         server_name your-lesta-domain.example;
         root ${app_dir}/public;
         index index.php;

         location / {
             try_files \$uri \$uri/ /index.php?\$query_string;
         }

         location ~ \.php\$ {
             include snippets/fastcgi-php.conf;
             fastcgi_pass unix:/run/php/php8.3-fpm.sock;
         }

         location ~ /\.(?!well-known).* {
             deny all;
         }
     }

   Put a real TLS certificate in front of this (the node agent's own
   enrollment, and every ACME-issued tenant certificate, both depend on
   APP_URL actually terminating HTTPS).

2. Queue worker (systemd unit, since QUEUE_CONNECTION=database in .env means
   queued jobs -- ACME issuance, provisioning -- sit in the database until a
   real worker processes them):

     [Unit]
     Description=LESta control-plane queue worker
     After=network.target

     [Service]
     User=www-data
     WorkingDirectory=${app_dir}
     ExecStart=/usr/bin/php ${app_dir}/artisan queue:work --tries=3 --sleep=3
     Restart=always

     [Install]
     WantedBy=multi-user.target

3. Scheduler (one crontab line -- ACME renewal, usage metrics, backups, and
   DKIM rotation are all real Schedule::command() entries in routes/console.php
   that never run without this):

     * * * * * cd ${app_dir} && php artisan schedule:run >> /dev/null 2>&1

Once the web server is up, log in and continue with the in-app Admin Guide
(/docs/admin-guide) to create your first hosting account, or the
Installation Guide (/docs/installation-guide) to enroll your first node.
NEXT
}

main() {
    parse_args "$@"
    preflight
    configure_env_file
    install_dependencies
    ensure_app_key
    run_migrations
    print_next_steps
}

main "$@"
