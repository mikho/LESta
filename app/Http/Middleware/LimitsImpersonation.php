<?php

namespace App\Http\Middleware;

use App\Actions\Support\StopImpersonation;
use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * An admin signed in as a customer (StartImpersonation) can help with the customer's hosting, not
 * take over the customer's sign-in: everything that changes how the account signs in or reaches
 * the panel from outside (email, password, two-factor, passkeys, API tokens, webhooks, deleting
 * the profile) is refused, and the impersonation ends on its own after MAXIMUM_MINUTES.
 */
class LimitsImpersonation
{
    private const int MAXIMUM_MINUTES = 60;

    private const array REFUSED_ROUTES = [
        'profile.update', 'profile.destroy', 'security.edit', 'user-password.update',
        'password.confirm', 'password.confirm.store',
        'two-factor.enable', 'two-factor.disable', 'two-factor.confirm', 'two-factor.qr-code',
        'two-factor.secret-key', 'two-factor.recovery-codes', 'two-factor.regenerate-recovery-codes',
        'passkey.store', 'passkey.registration-options', 'passkey.destroy', 'passkey.confirm', 'passkey.confirm-options',
        'api-tokens.index', 'api-tokens.store', 'api-tokens.destroy',
        'webhooks.index', 'webhooks.store', 'webhooks.test', 'webhooks.destroy',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession() || ! $request->session()->has('impersonator_id')) {
            return $next($request);
        }

        $startedAt = $request->session()->get('impersonation_started_at');

        if (! is_int($startedAt) || now()->getTimestamp() - $startedAt > self::MAXIMUM_MINUTES * 60) {
            app(StopImpersonation::class)->handle($request);

            Inertia::flash('toast', ['type' => 'info', 'message' => __('Signing in as the customer ended after :minutes minutes.', ['minutes' => self::MAXIMUM_MINUTES])]);

            return to_route('accounts.index');
        }

        abort_if(
            in_array((string) $request->route()?->getName(), self::REFUSED_ROUTES, true),
            403,
            __('This is not available while you are signed in as a customer.'),
        );

        return $next($request);
    }
}
