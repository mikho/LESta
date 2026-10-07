#!/bin/sh
# Regression test for install-cp.sh's platform preflight, against real PHP
# runtimes in Docker (official php:<version>-cli images, exact patch tags).
#
# 1. Below the floor (php:8.3): install-cp.sh --yes must exit 11 before
#    touching anything -- an existing .env byte-identical afterwards, and no
#    .env created when there wasn't one. php:8.4.0-cli would be the exact
#    "passes a major/minor check" case, but no such tag was ever published
#    (8.4.1 is the first), so 8.3 exercises the same ordering guarantee, and
#    php:8.4.1 with ext-sodium removed is the case a major/minor check can
#    never catch (right PHP version, missing locked extension).
# 2. Unsupported tooling (php:8.4.1 with Composer 2.2): PHP and every locked
#    extension are fine, so only Composer is wrong -- 2.2 predates the --lock
#    option of check-platform-reqs (added in 2.3). install-cp.sh must still
#    exit 11 before touching anything, with .env byte-identical afterwards,
#    and the log must show Composer itself rejecting --lock, so this can't
#    pass because of some unrelated exit 11.
# 3. At the supported minimum (php:8.4.1): the same preflight check passes,
#    and `composer install --no-dev` succeeds from the committed lock as-is
#    (never `composer update`), leaving composer.lock unchanged.
#
# Usage: .github/scripts/test-install-cp-platform-reqs.sh  (from the repo root)
set -eu

REPO=$(pwd)
if [ ! -f "${REPO}/install-cp.sh" ] || [ ! -f "${REPO}/composer.lock" ]; then
    echo "run from the repository root" >&2
    exit 2
fi

WORK=$(mktemp -d)
trap 'rm -rf "${WORK}" 2>/dev/null || true' EXIT

docker run --rm --entrypoint cat composer:2 /usr/bin/composer > "${WORK}/composer"
chmod +x "${WORK}/composer"

# The newest Composer release below the 2.3 floor for check-platform-reqs --lock.
docker run --rm --entrypoint cat composer:2.2 /usr/bin/composer > "${WORK}/composer-2.2"
chmod +x "${WORK}/composer-2.2"

# Stub node/npm that succeed: the php images have neither, and without them
# install-cp.sh could stop at its own node/npm check instead, making a
# "nothing was written" result say nothing about the platform check itself.
mkdir -p "${WORK}/stubs"
for cmd in node npm; do
    printf '#!/bin/sh\nexit 0\n' > "${WORK}/stubs/${cmd}"
    chmod +x "${WORK}/stubs/${cmd}"
done

# A clean copy of the tracked files only: never the developer's own .env,
# vendor/ or node_modules/.
fresh_checkout() {
    dest="$1"
    mkdir -p "${dest}"
    git -C "${REPO}" archive HEAD | tar -x -C "${dest}"
    # The working-tree install-cp.sh, so an uncommitted change under test is
    # what actually runs.
    cp "${REPO}/install-cp.sh" "${dest}/install-cp.sh"
}

run_in() {
    image="$1"
    dir="$2"
    shift 2
    docker run --rm --platform linux/amd64 \
        -v "${dir}":/app -v "${COMPOSER_BIN:-${WORK}/composer}":/usr/local/bin/composer:ro \
        -v "${WORK}/stubs":/stubs:ro -e PATH="/stubs:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin" \
        -w /app "${image}" sh -c "$*"
}

fail() {
    echo "FAIL: $1" >&2
    exit 1
}

echo "== below the floor (php:8.3), existing .env must stay byte-identical"
fresh_checkout "${WORK}/old-with-env"
printf 'APP_URL=http://sentinel.invalid\nDB_PASSWORD=sentinel\n' > "${WORK}/old-with-env/.env"
before=$(shasum -a 256 "${WORK}/old-with-env/.env" | cut -d' ' -f1)
set +e
run_in php:8.3-cli "${WORK}/old-with-env" './install-cp.sh --yes' > "${WORK}/old-with-env.log" 2>&1
rc=$?
set -e
[ "${rc}" -eq 11 ] || { cat "${WORK}/old-with-env.log"; fail "expected exit 11, got ${rc}"; }
grep -q 'composer.lock requirements' "${WORK}/old-with-env.log" || { cat "${WORK}/old-with-env.log"; fail "exited 11 but not from the platform check"; }
after=$(shasum -a 256 "${WORK}/old-with-env/.env" | cut -d' ' -f1)
[ "${before}" = "${after}" ] || fail ".env was modified"
echo "ok: exit 11, .env unchanged"

echo "== below the floor (php:8.3), no .env must be created"
fresh_checkout "${WORK}/old-no-env"
set +e
run_in php:8.3-cli "${WORK}/old-no-env" './install-cp.sh --yes' > "${WORK}/old-no-env.log" 2>&1
rc=$?
set -e
[ "${rc}" -eq 11 ] || { cat "${WORK}/old-no-env.log"; fail "expected exit 11, got ${rc}"; }
[ ! -e "${WORK}/old-no-env/.env" ] || fail ".env was created"
echo "ok: exit 11, no .env created"

echo "== supported PHP version but a missing locked extension (php:8.4.1 without sodium)"
# The case a major/minor PHP check can never catch: 8.4.1 passes it, but the
# lock needs ext-sodium. Official images load sodium from a removable ini.
fresh_checkout "${WORK}/no-sodium"
printf 'APP_URL=http://sentinel.invalid\nDB_PASSWORD=sentinel\n' > "${WORK}/no-sodium/.env"
before=$(shasum -a 256 "${WORK}/no-sodium/.env" | cut -d' ' -f1)
set +e
run_in php:8.4.1-cli "${WORK}/no-sodium" 'rm /usr/local/etc/php/conf.d/docker-php-ext-sodium.ini && ./install-cp.sh --yes' > "${WORK}/no-sodium.log" 2>&1
rc=$?
set -e
[ "${rc}" -eq 11 ] || { cat "${WORK}/no-sodium.log"; fail "expected exit 11, got ${rc}"; }
grep -q 'ext-sodium' "${WORK}/no-sodium.log" || { cat "${WORK}/no-sodium.log"; fail "exited 11 but not over ext-sodium"; }
after=$(shasum -a 256 "${WORK}/no-sodium/.env" | cut -d' ' -f1)
[ "${before}" = "${after}" ] || fail ".env was modified"
echo "ok: exit 11 over ext-sodium, .env unchanged"

echo "== supported PHP and extensions but Composer 2.2 (no check-platform-reqs --lock)"
fresh_checkout "${WORK}/old-composer"
printf 'APP_URL=http://sentinel.invalid\nDB_PASSWORD=sentinel\n' > "${WORK}/old-composer/.env"
before=$(shasum -a 256 "${WORK}/old-composer/.env" | cut -d' ' -f1)
set +e
COMPOSER_BIN="${WORK}/composer-2.2" run_in php:8.4.1-cli "${WORK}/old-composer" './install-cp.sh --yes' > "${WORK}/old-composer.log" 2>&1
rc=$?
set -e
[ "${rc}" -eq 11 ] || { cat "${WORK}/old-composer.log"; fail "expected exit 11, got ${rc}"; }
grep -q 'option does not exist' "${WORK}/old-composer.log" || { cat "${WORK}/old-composer.log"; fail "exited 11 but Composer did not reject --lock"; }
grep -q 'composer.lock requirements' "${WORK}/old-composer.log" || { cat "${WORK}/old-composer.log"; fail "exited 11 but not from the platform check"; }
after=$(shasum -a 256 "${WORK}/old-composer/.env" | cut -d' ' -f1)
[ "${before}" = "${after}" ] || fail ".env was modified"
echo "ok: exit 11 over unsupported Composer, .env unchanged"

echo "== supported minimum (php:8.4.1): platform check passes, install from the committed lock"
fresh_checkout "${WORK}/min"
lock_before=$(shasum -a 256 "${WORK}/min/composer.lock" | cut -d' ' -f1)
run_in php:8.4.1-cli "${WORK}/min" '
    set -e
    apt-get update -qq >/dev/null && apt-get install -y -qq unzip git >/dev/null
    composer check-platform-reqs --lock --no-dev
    composer install --no-dev --no-interaction --no-progress --optimize-autoloader
' > "${WORK}/min.log" 2>&1 || { tail -40 "${WORK}/min.log"; fail "install on php:8.4.1 failed"; }
lock_after=$(shasum -a 256 "${WORK}/min/composer.lock" | cut -d' ' -f1)
[ "${lock_before}" = "${lock_after}" ] || fail "composer.lock changed during install"
[ -f "${WORK}/min/vendor/autoload.php" ] || fail "vendor/autoload.php missing after install"
echo "ok: php 8.4.1 installs the committed lock unchanged"

echo "All install-cp platform checks passed."
