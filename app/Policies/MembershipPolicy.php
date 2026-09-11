<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\Membership;
use App\Models\User;

class MembershipPolicy
{
    public function view(User $user, Membership $membership): bool
    {
        return $this->isAccountOwner($user, $membership) || $user->hasPermission('memberships.view');
    }

    public function create(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner') || $user->hasPermission('memberships.create');
    }

    public function update(User $user, Membership $membership): bool
    {
        return $this->isAccountOwner($user, $membership) || $user->hasPermission('memberships.update');
    }

    public function delete(User $user, Membership $membership): bool
    {
        return $this->isAccountOwner($user, $membership) || $user->hasPermission('memberships.delete');
    }

    /**
     * The account_id !== null guard is a business rule, not an
     * authorization gate: a platform-scope membership (another admin's own
     * seat) is never a valid impersonation target regardless of how many
     * permissions the acting admin holds, since there is no tenant identity
     * behind it to swap into.
     */
    public function impersonate(User $admin, Membership $membership): bool
    {
        return $admin->hasPermission('memberships.impersonate') && $membership->account_id !== null;
    }

    private function isAccountOwner(User $user, Membership $membership): bool
    {
        if ($membership->account === null) {
            return false;
        }

        return $user->hasAccountRole($membership->account, 'owner');
    }
}
