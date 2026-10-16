<?php

use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Http\Middleware\RequireApiAbility;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Both files define their own full prefix (agent.php's "agent/v1",
        // internal-adminer.php's "internal/adminer-credentials") and are
        // registered identically: Laravel's own stateless "api" middleware
        // group (no EncryptCookies/StartSession/CSRF), never Sanctum's
        // stateful guard, and no leading "api" segment (apiPrefix: '' below)
        // -- every client (the Go daemon, .install/lib/enrollment.sh, and
        // now .install/services/adminer/vendor/adminer-lesta-login.php) posts
        // to "<control_plane_url>/<its own prefix>/...", never
        // "/api/...".
        api: [
            __DIR__.'/../routes/agent.php',
            __DIR__.'/../routes/internal-adminer.php',
            __DIR__.'/../routes/internal-webmail.php',
            // The public API carries its own "api/v1" prefix like the others.
            __DIR__.'/../routes/api.php',
        ],
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: '',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias(['api.ability' => RequireApiAbility::class]);

        // Which proxies are believed is set in AppServiceProvider from config('app.trusted_proxies')
        // (TRUSTED_PROXIES): the local machine by default, where nginx terminates TLS. Without
        // trusting that hop, $request->secure() would evaluate the internal nginx->php-fpm hop
        // (plain HTTP), so the "secure" cookie flag below would never apply.
        $middleware->trustProxies(headers: Request::HEADER_X_FORWARDED_FOR
            | Request::HEADER_X_FORWARDED_HOST
            | Request::HEADER_X_FORWARDED_PORT
            | Request::HEADER_X_FORWARDED_PROTO);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            SecurityHeaders::class,
            HandleAppearance::class,
            HandleInertiaRequests::class,
            // Not AddLinkHeadersForPreloadedAssets: its Link header repeats every preload tag the page
            // already has and made the response header larger than nginx's default FastCGI buffer
            // once the security headers and encrypted cookies were added (a 502 from nginx).
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
