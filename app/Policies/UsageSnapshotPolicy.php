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
 * visibility: a single account's usage via ?account=, and the all-accounts summary list. There is no create/update/delete
 * ability at all: rows are written exclusively by RecordsUsageSnapshot from a real completed
 * metrics.usage.v1 operation, and pruned exclusively by App\Console\Commands\PruneUsageSnapshots
 * -- never a user action of any kind.
 */
class UsageSnapshotPolicy
{
    public function viewAny(User $user, Account $account): bool
    {
        return $user->hasAnyAccountMembership($account) || $user->hasPermission('usage.view_any');
    }

    /**
     * The provider admin's read-only usage summary across every account, grouped by node.
     */
    public function viewAnyAcrossAccounts(User $user): bool
    {
        return $user->hasPermission('usage.view_any');
    }

    public function view(User $user, UsageSnapshot $usageSnapshot): bool
    {
        return $user->hasAnyAccountMembership($usageSnapshot->account) || $user->hasPermission('usage.view_any');
    }
}
