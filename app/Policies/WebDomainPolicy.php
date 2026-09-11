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

    /**
     * Also recognizes accounts.suspend/unsuspend/delete, the exact permission that already
     * authorized the owning Account-level action (SuspendAccount/UnsuspendAccount/DeleteAccount)
     * these three abilities exist to be cascaded from: an admin permitted to suspend/unsuspend/
     * delete a whole account cannot be blocked from the same action's own unavoidable cascade
     * into that account's individual web domains, or the top-level action would fail outright the
     * moment any account with real resources hit it. Not a new permission grant, just making the
     * one already given fully work.
     */
    public function suspend(User $user, WebDomain $webDomain): bool
    {
        return $user->hasAccountRole($webDomain->account, 'owner') || $user->hasPermission('accounts.suspend');
    }

    public function unsuspend(User $user, WebDomain $webDomain): bool
    {
        return $user->hasAccountRole($webDomain->account, 'owner') || $user->hasPermission('accounts.unsuspend');
    }

    public function delete(User $user, WebDomain $webDomain): bool
    {
        return $user->hasAccountRole($webDomain->account, 'owner') || $user->hasPermission('accounts.delete');
    }
}
