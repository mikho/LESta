<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\CronJob;
use App\Models\User;

class CronJobPolicy
{
    public function viewAny(User $user, Account $account): bool
    {
        return $user->memberships()->where('account_id', $account->id)->exists();
    }

    public function view(User $user, CronJob $cronJob): bool
    {
        return $user->memberships()->where('account_id', $cronJob->account_id)->exists();
    }

    public function create(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner');
    }

    public function update(User $user, CronJob $cronJob): bool
    {
        return $user->hasAccountRole($cronJob->account, 'owner');
    }

    public function suspend(User $user, CronJob $cronJob): bool
    {
        return $user->hasAccountRole($cronJob->account, 'owner');
    }

    public function unsuspend(User $user, CronJob $cronJob): bool
    {
        return $user->hasAccountRole($cronJob->account, 'owner');
    }

    public function delete(User $user, CronJob $cronJob): bool
    {
        return $user->hasAccountRole($cronJob->account, 'owner');
    }
}
