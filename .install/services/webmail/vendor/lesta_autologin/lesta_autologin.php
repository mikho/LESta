<?php

/**
 * LESta's own Roundcube auto-login plugin (mail.webmail.v1). A tenant reaches
 * https://<mail-hostname>/?_lesta_token=... from "Open webmail" in the panel;
 * the token is single-use and short-lived (App\Actions\Mail\PrepareWebmailSession
 * on the control plane), redeemed here over one synchronous HTTPS call to the
 * control plane's own token-only /internal/webmail-credentials/{token}
 * endpoint.
 *
 * Built on Roundcube's own documented hook pattern (plugins/autologon in the
 * real 1.7.4 source, confirmed against public_html/index.php's own login
 * flow), with two deliberate differences from that sample:
 *
 * - No `$task = 'login'` restriction: with it, the plugin never loads for a
 *   browser already logged into a different mailbox, so "Open webmail" for
 *   mailbox B would silently land on mailbox A. startup() forces the login
 *   task and authenticate() replaces any existing session instead.
 * - The IMAP host is never taken from the credential response: index.php
 *   seeds `host` from this node's own config (imap_host), and this plugin
 *   leaves it alone, so a token can only ever log into this node's own
 *   Dovecot.
 *
 * Roundcube's own native login form stays enabled (mailbox owners who aren't
 * panel users need it). A bad, expired or reused token just falls back to
 * that form with Roundcube's own "invalid request" message, never a blank
 * error page. After a successful login Roundcube redirects to ?_task=mail
 * itself, so the spent token never rides forward into the next request.
 */
class lesta_autologin extends rcube_plugin
{
    #[\Override]
    public function init()
    {
        $this->add_hook('startup', [$this, 'startup']);
        $this->add_hook('authenticate', [$this, 'authenticate']);
    }

    public function startup($args)
    {
        if ($this->token() !== '') {
            $args['task'] = 'login';
            $args['action'] = 'login';
        }

        return $args;
    }

    public function authenticate($args)
    {
        $token = $this->token();

        if ($token === '') {
            return $args;
        }

        $credentials = $this->redeem($token);

        if ($credentials === null) {
            $args['valid'] = false;

            return $args;
        }

        rcmail::get_instance()->kill_session();

        $args['user'] = $credentials['username'];
        $args['pass'] = $credentials['password'];
        $args['cookiecheck'] = false;
        $args['valid'] = true;

        return $args;
    }

    private function token(): string
    {
        $token = rcube_utils::get_input_string('_lesta_token', rcube_utils::INPUT_GET);

        return is_string($token) && preg_match('/^[A-Za-z0-9]{1,128}$/', $token) ? $token : '';
    }

    /**
     * @return array{username: string, password: string}|null
     */
    private function redeem(string $token): ?array
    {
        $base = @file_get_contents(__DIR__ . '/control-plane-url.txt');

        if ($base === false || trim($base) === '') {
            rcube::raise_error('lesta_autologin: control-plane-url.txt is missing', true, false);

            return null;
        }

        $url = rtrim(trim($base), '/') . '/internal/webmail-credentials/' . rawurlencode($token);

        // Default stream TLS verification is deliberately left on: the
        // control plane's certificate is trusted through the node's own
        // system CA bundle, the same path enrollment already relies on.
        $context = stream_context_create(['http' => [
            'method' => 'GET',
            'header' => "Accept: application/json\r\n",
            'timeout' => 10,
            'ignore_errors' => true,
        ]]);

        $body = @file_get_contents($url, false, $context);

        $status = null;
        foreach ($http_response_header ?? [] as $header) {
            if (preg_match('~^HTTP/\S+\s+(\d+)~', $header, $m)) {
                $status = (int) $m[1];
            }
        }

        if ($body === false || $status !== 200) {
            return null;
        }

        $credentials = json_decode($body, true);

        if (!is_array($credentials) || !is_string($credentials['username'] ?? null) || !is_string($credentials['password'] ?? null)) {
            return null;
        }

        return ['username' => $credentials['username'], 'password' => $credentials['password']];
    }
}
