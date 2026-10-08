<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\AccountIpRule;
use App\Models\User;

class AccountIpRulePolicy
{
    public function viewAny(User $user, Account $account): bool
    {
        return $user->hasAnyAccountMembership($account);
    }

    public function create(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner');
    }

    public function delete(User $user, AccountIpRule $rule): bool
    {
        return $user->hasAccountRole($rule->account, 'owner');
    }
}
