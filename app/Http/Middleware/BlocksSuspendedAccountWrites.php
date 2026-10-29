<?php

namespace App\Http\Middleware;

use App\Models\Account;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A suspended account can be read, not changed: the same rule the API applies
 * (RequireApiAbility), for the panel. Every request that changes something on an account that is
 * suspended is refused, except lifting the suspension itself (AccountPolicy decides who may) and
 * preparing a download of the account's own backup. The provider is never refused: it is what
 * acts on suspended accounts.
 *
 * The account comes from the route: a bound account, or the account a bound resource belongs to,
 * or, on the customer pages that create something, the user's own account.
 */
class BlocksSuspendedAccountWrites
{
    private const array EXEMPT_ROUTES = ['accounts.unsuspend', 'account-backups.prepare-download'];

    private const array CUSTOMER_ROUTE_PREFIXES = ['domains.', 'dns.', 'mail.', 'tenant-databases.', 'cron-jobs.', 'ip-rules.', 'account-backups.'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($request->isMethodSafe() || $user === null || $user->hasPermission('accounts.update')) {
            return $next($request);
        }

        $routeName = (string) $request->route()?->getName();

        if (in_array($routeName, self::EXEMPT_ROUTES, true)) {
            return $next($request);
        }

        $account = $this->accountOf($request);

        if ($account === null) {
            foreach (self::CUSTOMER_ROUTE_PREFIXES as $prefix) {
                if (str_starts_with($routeName, $prefix)) {
                    $account = $user->memberships()->whereNotNull('account_id')->with('account')->first()?->account;

                    break;
                }
            }
        }

        if ($account?->isSuspended()) {
            abort(403, __('This account is suspended. It can be read, not changed, until it is unsuspended.'));
        }

        return $next($request);
    }

    private function accountOf(Request $request): ?Account
    {
        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Account) {
                return $parameter;
            }

            if ($parameter instanceof Model && method_exists($parameter, 'account') && $parameter->getAttribute('account_id') !== null) {
                $account = $parameter->getRelationValue('account');

                if ($account instanceof Account) {
                    return $account;
                }
            }
        }

        return null;
    }
}
