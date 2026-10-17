<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiProblemException;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The platform permission an admin route needs ("api.permission:accounts.suspend"), the same one
 * that gates the panel page. Checked on its own because several policies also let an account's
 * owner act on it: the admin surface is for platform permissions only. The Action's own policy
 * check still runs afterwards.
 */
class RequireApiPermission
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $permission): Response
    {
        if (! $request->user()?->hasPermission($permission)) {
            throw new ApiProblemException(403, 'missing-permission', "Your login does not have the {$permission} permission.");
        }

        return $next($request);
    }
}
