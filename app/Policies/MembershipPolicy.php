<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\Membership;
use App\Models\User;

class MembershipPolicy
{
    public function view(User $user, Membership $membership): bool
    {
        return $this->isAccountOwner($user, $membership);
    }

    public function create(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner');
    }

    public function update(User $user, Membership $membership): bool
    {
        return $this->isAccountOwner($user, $membership);
    }

    public function delete(User $user, Membership $membership): bool
    {
        return $this->isAccountOwner($user, $membership);
    }

    public function impersonate(User $admin, Membership $membership): bool
    {
        return $admin->isProviderAdmin() && $membership->account_id !== null;
    }

    private function isAccountOwner(User $user, Membership $membership): bool
    {
        if ($membership->account === null) {
            return false;
        }

        return $user->hasAccountRole($membership->account, 'owner');
    }
}
