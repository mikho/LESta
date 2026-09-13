<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\UsageSnapshot;
use App\Models\User;

/**
 * Unlike BackupPolicy, usage snapshots are genuinely tenant-visible data (per the governing
 * rewrite plan's own "usage snapshots" as part of the account owner's own web-hosting lifecycle):
 * any member of the owning account can view them, mirroring WebDomainPolicy's own view/viewAny
 * exactly, not just a provider admin. usage.view_any additionally grants an admin cross-account
 * visibility, for a future usage-across-all-accounts dashboard. There is no create/update/delete
 * ability at all: rows are written exclusively by RecordsUsageSnapshot from a real completed
 * metrics.usage.v1 operation, and pruned exclusively by App\Console\Commands\PruneUsageSnapshots
 * -- never a user action of any kind.
 */
class UsageSnapshotPolicy
{
    public function viewAny(User $user, Account $account): bool
    {
        return $user->memberships()->where('account_id', $account->id)->exists() || $user->hasPermission('usage.view_any');
    }

    public function view(User $user, UsageSnapshot $usageSnapshot): bool
    {
        return $user->memberships()->where('account_id', $usageSnapshot->account_id)->exists() || $user->hasPermission('usage.view_any');
    }
}
