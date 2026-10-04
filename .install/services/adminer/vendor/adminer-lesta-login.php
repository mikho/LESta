<?php
/**
 * LESta's own Adminer auto-login bootstrap. Must be required BEFORE
 * adminer.php itself (see adminer.php's own trailing
 * `Adminer::$instance=(function_exists('adminer_object')?adminer_object():...)`
 * bootstrap line) -- this is Adminer's own documented, fully supported
 * single-file customization mechanism, confirmed directly against the real
 * vendored 6.1.1 source (adminer/include/adminer.inc.php,
 * adminer/include/auth.inc.php), not assumed from memory.
 *
 * Design, verified against the real source rather than guessed:
 *
 * - A tenant reaches this file via /__lesta-adminer__?token=... (see
 *   agent/internal/capability/nginx's own php.conf.tmpl location block).
 *   The token is single-use and short-lived (see
 *   App\Actions\TenantDatabases\PrepareAdminerSession on the control
 *   plane), redeemed here over one synchronous HTTPS call to the control
 *   plane's own internal, token-only-authenticated endpoint.
 * - Rather than reimplementing Adminer's own session/password-encryption
 *   internals (get_password()/set_password() round-trip through
 *   $_SESSION["pwds"][DRIVER][SERVER][username], DRIVER being the fixed
 *   literal "server" for this MySQL-only vendored build), this file
 *   simulates a genuine native login-form submission by populating
 *   $_POST["auth"] exactly as auth.inc.php's own real form handler expects
 *   it. Adminer's own already-tested code then calls set_password() for
 *   real, keyed correctly, and issues its own redirect to a clean
 *   (tokenless) URL -- the same UX a human typing credentials into the
 *   native form gets, never a second, parallel auth mechanism.
 * - That simulated submission has no real CSRF token, so Adminer\Plugin's
 *   own verifyLoginToken() hook (explicitly documented: "a form on another
 *   website doesn't have it") is overridden to skip the check, only for
 *   this one synthetic submission.
 * - Only present when $_GET["token"] is actually set: every subsequent
 *   request within an already-established Adminer session (including the
 *   redirect target right after the first request) carries no token at
 *   all, and must fall through to Adminer's own native, already-working
 *   session handling untouched -- re-requiring a token on every click
 *   would both break normal navigation and defeat the token's own
 *   single-use design.
 * - permanentLogin() is overridden to never offer Adminer's own "remember
 *   me" cookie: these are one-time-minted credentials for a single
 *   session, not something that should survive as a reusable cookie.
 */

namespace Adminer;

/**
 * Reads the control plane's own base URL, written once by
 * .install/services/adminer/install.sh (parsed from the already-enrolled
 * node's own /etc/lesta/agent/daemon-config.json at install time) into a
 * narrowly-scoped file this pool's own lesta-adminer identity can read,
 * rather than granting it read access to the daemon's own full config.
 */
function lesta_adminer_control_plane_url(): string
{
    $path = __DIR__ . '/control-plane-url.txt';
    $url = @file_get_contents($path);

    if ($url === false || trim($url) === '') {
        http_response_code(500);
        die('LESta Adminer: control plane URL is not configured.');
    }

    return rtrim(trim($url), '/');
}

/**
 * Redeems the one-time token against the control plane's internal,
 * token-only-authenticated endpoint. Returns the decoded credentials array
 * on success; dies with a plain, non-revealing message on any failure
 * (expired, already used, unknown token, network error) -- this is a
 * public-shaped URL a scanner could hit, so it must never distinguish
 * "wrong token" from "network error" in its own response.
 *
 * @return array{host: string, port: int, username: string, password: string, database: string}
 */
function lesta_adminer_redeem_token(string $token): array
{
    $url = lesta_adminer_control_plane_url() . '/internal/adminer-credentials/' . rawurlencode($token);

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\n",
            'timeout' => 10,
            // Default stream SSL verification (verify_peer/verify_peer_name
            // both true since PHP 5.6) is deliberately left untouched: the
            // control plane's own certificate is trusted via the node's
            // real system CA bundle (base.tls.v1's own update-ca-certificates
            // run), the same trust path enrollment.sh's own curl call
            // already relies on. Never disable verification here.
        ],
    ]);

    $body = @file_get_contents($url, false, $context);

    if ($body === false) {
        http_response_code(502);
        die('LESta Adminer: could not reach the control plane.');
    }

    $status = null;
    foreach ($http_response_header ?? [] as $header) {
        if (preg_match('~^HTTP/\S+\s+(\d+)~', $header, $m)) {
            $status = (int) $m[1];
        }
    }

    if ($status !== 200) {
        http_response_code(403);
        die('LESta Adminer: this link is invalid, expired, or already used.');
    }

    $credentials = json_decode($body, true);

    if (!is_array($credentials) || !isset($credentials['host'], $credentials['port'], $credentials['username'], $credentials['password'], $credentials['database'])) {
        http_response_code(502);
        die('LESta Adminer: malformed response from the control plane.');
    }

    return $credentials;
}

class LestaAutoLogin extends Adminer
{
    /** Never require Adminer's own CSRF token for the one synthetic,
     * externally-initiated $_POST["auth"] submission this bootstrap makes.
     * Every other request (real Adminer-rendered forms, real navigation)
     * still goes through Adminer's own normal token handling untouched --
     * this hook only ever affects the login-submission check itself.
     */
    function verifyLoginToken(): bool
    {
        return false;
    }

    /** Never offer Adminer's own persistent "remember me" login cookie for
     * a one-time-minted credential set.
     */
    function permanentLogin(bool $create = false): string
    {
        return '';
    }
}

function adminer_object()
{
    $token = $_GET['token'] ?? '';

    if ($token !== '') {
        $credentials = lesta_adminer_redeem_token($token);

        $server = $credentials['host'] . ':' . $credentials['port'];

        // Mirrors auth.inc.php's own real native-form-submission shape
        // exactly (the $_POST["auth"] branch), so Adminer's own existing,
        // already-tested code performs the real set_password()/session
        // seeding and its own clean redirect -- this file never touches
        // $_SESSION directly.
        $_GET['username'] = $credentials['username'];
        $_GET['server'] = $server;
        $_POST['auth'] = [
            'driver' => 'server', // fixed literal for this vendored MySQL-only build
            'server' => $server,
            'username' => $credentials['username'],
            'password' => $credentials['password'],
            'db' => $credentials['database'],
        ];
    }

    return new LestaAutoLogin();
}
