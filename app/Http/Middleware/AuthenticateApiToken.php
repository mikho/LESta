<?php

namespace App\Http\Middleware;

use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates a personal API token from the Authorization header (never from the URL) and acts as
 * its user for the rest of the request, without a session. The token is on the request's attribute
 * bag under "api_token" for the ability and account checks that follow.
 */
class AuthenticateApiToken
{
    /** Failed tokens one address may present per minute before it is refused outright. */
    private const int MAX_FAILURES_PER_MINUTE = 30;

    /** last_used_at is written at most this often per token, so a busy script is not a write per call. */
    private const int TOUCH_EVERY_SECONDS = 60;

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Counted per address before any lookup, so new random tokens cannot get around it (the
        // node-credential rule, security audit finding 6).
        $failureKey = 'api-auth-failures:'.$request->ip();

        if (RateLimiter::tooManyAttempts($failureKey, self::MAX_FAILURES_PER_MINUTE)) {
            abort(429, 'Too many failed attempts.');
        }

        $plaintext = $request->bearerToken();

        $token = $plaintext === null ? null : ApiToken::query()
            ->with('user')
            ->where('token_hash', ApiToken::hashToken($plaintext))
            ->first();

        if ($token === null || $token->isExpired() || $token->user === null) {
            RateLimiter::hit($failureKey, 60);

            abort(401, 'Missing, invalid or expired API token.');
        }

        if ($token->last_used_at === null || $token->last_used_at->diffInSeconds(now()) >= self::TOUCH_EVERY_SECONDS || $token->last_used_ip !== $request->ip()) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        Auth::setUser($token->user);
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }
}
