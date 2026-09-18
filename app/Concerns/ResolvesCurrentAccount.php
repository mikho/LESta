<?php

namespace App\Concerns;

use App\Models\Account;
use App\Models\User;

/**
 * Was duplicated verbatim across seven controllers (Dashboard, Domains, Dns, Mail,
 * TenantDatabases, CronJobs, Usage) -- proven costly, not just a style nitpick: the "show a
 * graceful no-hosting-account state instead of a 404" fix had to be applied to all seven
 * identical copies by hand. One implementation here now.
 */
trait ResolvesCurrentAccount
{
    /**
     * The user's own first account-scoped membership's account, or null if they have none. A
     * user who belongs to more than one account only ever sees their first here -- there is no
     * account switcher in this application yet (see the User Guide's own "still open" list).
     */
    private function resolveAccount(User $user): ?Account
    {
        return $user->memberships()->whereNotNull('account_id')->with('account')->first()?->account;
    }
}
