<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/agent.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        // No leading "api" segment: routes/agent.php already defines its own
        // "agent/v1" prefix, and every network client (the Go daemon,
        // .install/lib/enrollment.sh) posts to "<control_plane_url>/agent/v1/...",
        // never "/api/agent/v1/...". Registering it via the api: parameter
        // (rather than requiring it from web.php) is what actually matters here:
        // it uses Laravel's own stateless "api" middleware group (no
        // EncryptCookies/StartSession/CSRF), never Sanctum's stateful guard.
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Trusts X-Forwarded-* only from loopback and RFC1918 private ranges, never a public
        // address: this app is documented (README.md, the Installation Guide) as deployed behind
        // nginx terminating TLS on the same host or the same private network, never reached
        // directly by a client. Without this, $request->secure() evaluates the internal
        // nginx->php-fpm hop (plain HTTP), not the real client connection, so the "secure" cookie
        // flag below would never actually apply even when the app is genuinely served over HTTPS.
        $middleware->trustProxies(at: [
            '127.0.0.1',
            '::1',
            '10.0.0.0/8',
            '172.16.0.0/12',
            '192.168.0.0/16',
        ], headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
