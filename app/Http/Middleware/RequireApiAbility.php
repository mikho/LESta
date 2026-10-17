<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiProblemException;
use App\Models\Account;
use App\Models\ApiToken;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Refuses a request whose API token lacks the ability the route names (for example
 * "api.ability:web:read"), or that addresses an account the token is restricted away from. The
 * policy check in the Action or controller still runs afterwards: this only narrows. A suspended
 * account can be read but not changed.
 */
class RequireApiAbility
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $ability): Response
    {
        $token = $request->attributes->get('api_token');

        if (! $token instanceof ApiToken || ! $token->allows($ability)) {
            throw new ApiProblemException(403, 'missing-ability', "This token does not have the {$ability} ability.");
        }

        $account = $request->route('account');

        if ($account instanceof Account && ! $token->reaches($account)) {
            throw new ApiProblemException(403, 'account-restricted', 'This token is restricted to another account.');
        }

        // Platform administration acts on suspended accounts too (it is what unsuspends them).
        if ($account instanceof Account && str_ends_with($ability, ':write') && $ability !== 'admin:write' && $account->isSuspended()) {
            throw new ApiProblemException(403, 'account-suspended', 'This account is suspended. It can be read, not changed.');
        }

        return $next($request);
    }
}
