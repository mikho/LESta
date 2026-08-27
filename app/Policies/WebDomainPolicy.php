<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;
use App\Models\WebDomain;

class WebDomainPolicy
{
    public function viewAny(User $user, Account $account): bool
    {
        return $user->memberships()->where('account_id', $account->id)->exists();
    }

    public function view(User $user, WebDomain $webDomain): bool
    {
        return $user->memberships()->where('account_id', $webDomain->account_id)->exists();
    }

    public function create(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner');
    }

    public function update(User $user, WebDomain $webDomain): bool
    {
        return $user->hasAccountRole($webDomain->account, 'owner');
    }

    public function suspend(User $user, WebDomain $webDomain): bool
    {
        return $user->hasAccountRole($webDomain->account, 'owner');
    }

    public function unsuspend(User $user, WebDomain $webDomain): bool
    {
        return $user->hasAccountRole($webDomain->account, 'owner');
    }

    public function delete(User $user, WebDomain $webDomain): bool
    {
        return $user->hasAccountRole($webDomain->account, 'owner');
    }
}
