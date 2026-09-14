<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\CronJob;
use App\Models\User;

class CronJobPolicy
{
    public function viewAny(User $user, Account $account): bool
    {
        return $user->hasAnyAccountMembership($account);
    }

    public function view(User $user, CronJob $cronJob): bool
    {
        return $user->hasAnyAccountMembership($cronJob->account);
    }

    public function create(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner');
    }

    public function update(User $user, CronJob $cronJob): bool
    {
        return $user->hasAccountRole($cronJob->account, 'owner');
    }

    /**
     * Also recognizes accounts.suspend/unsuspend/delete, the exact permission that already
     * authorized the owning Account-level action (SuspendAccount/UnsuspendAccount/DeleteAccount)
     * these three abilities exist to be cascaded from -- mirrors WebDomainPolicy's own identical
     * fix (Phase 28), which this policy never received: an admin permitted to suspend/unsuspend/
     * delete a whole account would otherwise throw mid-cascade the moment that account had a real
     * cron job, confirmed directly rather than assumed.
     */
    public function suspend(User $user, CronJob $cronJob): bool
    {
        return $user->hasAccountRole($cronJob->account, 'owner') || $user->hasPermission('accounts.suspend');
    }

    public function unsuspend(User $user, CronJob $cronJob): bool
    {
        return $user->hasAccountRole($cronJob->account, 'owner') || $user->hasPermission('accounts.unsuspend');
    }

    public function delete(User $user, CronJob $cronJob): bool
    {
        return $user->hasAccountRole($cronJob->account, 'owner') || $user->hasPermission('accounts.delete');
    }
}
