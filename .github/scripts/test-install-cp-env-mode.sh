#!/bin/sh
# Regression test for install-cp.sh's secure_env_file: the .env (APP_KEY, database password) and
# storage/ must not be readable by other local users, however the file was written before.
# Usage: .github/scripts/test-install-cp-env-mode.sh  (from the repo root)
set -eu

REPO=$(pwd)
WORK=$(mktemp -d)
trap 'rm -rf "${WORK}"' EXIT

for fn in restrict_env_mode secure_env_file set_env_value; do
    awk -v fn="${fn}" '$0 ~ "^" fn "\\(\\) \\{" {p=1} p{print} p&&/^\}/{exit}' "${REPO}/install-cp.sh" >> "${WORK}/fn.sh"
    grep -q "^${fn}() {" "${WORK}/fn.sh" || { echo "FAIL: ${fn} not found in install-cp.sh" >&2; exit 1; }
done

cd "${WORK}"
mkdir -p storage/logs
: > .env
: > storage/logs/laravel.log
chmod 0644 .env storage/logs/laravel.log
chmod 0755 storage storage/logs

# shellcheck disable=SC2034
ENV_FILE=".env"
fail_mutation() { echo "FAIL: $*" >&2; exit 1; }

# shellcheck disable=SC1091
. "${WORK}/fn.sh"
secure_env_file

mode() { stat -c '%a' "$1" 2>/dev/null || stat -f '%Lp' "$1"; }

[ "$(mode .env)" = "640" ] || { echo "FAIL: .env mode is $(mode .env), want 640" >&2; exit 1; }
[ "$(mode storage)" = "750" ] || { echo "FAIL: storage mode is $(mode storage), want 750" >&2; exit 1; }
[ "$(mode storage/logs/laravel.log)" = "640" ] || { echo "FAIL: log mode is $(mode storage/logs/laravel.log), want 640" >&2; exit 1; }

# Rewriting a value must never leave the .env readable by others, not even for a moment: the new
# file gets its mode before it replaces the old one.
chmod 0644 .env
set_env_value APP_KEY "base64:secret"
[ "$(mode .env)" = "640" ] || { echo "FAIL: .env mode after set_env_value is $(mode .env), want 640" >&2; exit 1; }
grep -qx 'APP_KEY=base64:secret' .env || { echo "FAIL: set_env_value did not write the value" >&2; exit 1; }

echo "ok: .env is 0640 and storage/ has no access for others"
