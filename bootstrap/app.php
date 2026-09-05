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
