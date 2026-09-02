<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\TenantDatabase;
use App\Models\User;

class TenantDatabasePolicy
{
    public function viewAny(User $user, Account $account): bool
    {
        return $user->memberships()->where('account_id', $account->id)->exists();
    }

    public function view(User $user, TenantDatabase $tenantDatabase): bool
    {
        return $user->memberships()->where('account_id', $tenantDatabase->account_id)->exists();
    }

    public function create(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner');
    }

    public function update(User $user, TenantDatabase $tenantDatabase): bool
    {
        return $user->hasAccountRole($tenantDatabase->account, 'owner');
    }

    public function suspend(User $user, TenantDatabase $tenantDatabase): bool
    {
        return $user->hasAccountRole($tenantDatabase->account, 'owner');
    }

    public function unsuspend(User $user, TenantDatabase $tenantDatabase): bool
    {
        return $user->hasAccountRole($tenantDatabase->account, 'owner');
    }

    public function delete(User $user, TenantDatabase $tenantDatabase): bool
    {
        return $user->hasAccountRole($tenantDatabase->account, 'owner');
    }
}
