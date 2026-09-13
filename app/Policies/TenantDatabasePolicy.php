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

    /**
     * Also recognizes accounts.suspend/unsuspend/delete, the exact permission that already
     * authorized the owning Account-level action (SuspendAccount/UnsuspendAccount/DeleteAccount)
     * these three abilities exist to be cascaded from -- mirrors WebDomainPolicy's own identical
     * fix (Phase 28), which this policy never received: an admin permitted to suspend/unsuspend/
     * delete a whole account would otherwise throw mid-cascade the moment that account had a real
     * tenant database, confirmed directly rather than assumed.
     */
    public function suspend(User $user, TenantDatabase $tenantDatabase): bool
    {
        return $user->hasAccountRole($tenantDatabase->account, 'owner') || $user->hasPermission('accounts.suspend');
    }

    public function unsuspend(User $user, TenantDatabase $tenantDatabase): bool
    {
        return $user->hasAccountRole($tenantDatabase->account, 'owner') || $user->hasPermission('accounts.unsuspend');
    }

    public function delete(User $user, TenantDatabase $tenantDatabase): bool
    {
        return $user->hasAccountRole($tenantDatabase->account, 'owner') || $user->hasPermission('accounts.delete');
    }
}
