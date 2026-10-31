<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiProblemException;
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
            throw new ApiProblemException(429, 'too-many-failed-tokens', 'Too many failed tokens from this address. Wait a minute.', ['Retry-After' => (string) RateLimiter::availableIn($failureKey)]);
        }

        $plaintext = $request->bearerToken();

        $token = $plaintext === null ? null : ApiToken::query()
            ->with('user')
            ->where('token_hash', ApiToken::hashToken($plaintext))
            ->first();

        if ($token === null || $token->isExpired() || $token->user === null) {
            RateLimiter::hit($failureKey, 60);

            throw new ApiProblemException(401, 'invalid-token', 'Missing, invalid, revoked or expired API token.');
        }

        if (! $this->userMayStillHold($token)) {
            throw new ApiProblemException(401, 'token-no-longer-allowed', 'The user of this API token no longer has the role it was created with.');
        }

        if ($token->last_used_at === null || $token->last_used_at->diffInSeconds(now()) >= self::TOUCH_EVERY_SECONDS || $token->last_used_ip !== $request->ip()) {
            $token->forceFill(['last_used_at' => now(), 'last_used_ip' => $request->ip()])->saveQuietly();
        }

        Auth::setUser($token->user);
        $request->setUserResolver(fn () => $token->user);
        $request->attributes->set('api_token', $token);

        return $next($request);
    }

    /**
     * What CreateApiToken required when the token was made must still hold: its user may use API
     * tokens, a token with the admin abilities belongs to a platform user, and a token restricted
     * to an account belongs to that account's owner. A token never outlives the role that allowed it.
     */
    private function userMayStillHold(ApiToken $token): bool
    {
        $user = $token->user;

        if (! $user->can('create', ApiToken::class)) {
            return false;
        }

        if (array_intersect($token->abilities, ApiToken::ADMIN_ABILITIES) !== [] && ! $user->isPlatformUser()) {
            return false;
        }

        return $token->account_id === null || ($token->account !== null && $user->hasAccountRole($token->account, 'owner'));
    }
}
