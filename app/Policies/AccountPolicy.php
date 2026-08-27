<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AccountPolicy
{
    public function view(User $user, Account $account): bool
    {
        return $user->memberships()->where('account_id', $account->id)->exists();
    }

    public function create(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner');
    }

    public function update(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner');
    }

    public function suspend(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner');
    }

    public function unsuspend(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner');
    }

    public function delete(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner');
    }

    public function viewAsSupport(User $user, Account $account): bool
    {
        return $user->isProviderAdmin();
    }
}
