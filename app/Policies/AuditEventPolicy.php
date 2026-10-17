<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\User;

class AuditEventPolicy
{
    /**
     * The platform-wide log: every event, every account.
     */
    public function viewAll(User $user): bool
    {
        return $user->hasPermission('audit_events.view_any');
    }

    /**
     * One account's log: any member of the account (a reseller account's members for the
     * accounts it manages), as for the account's other pages.
     */
    public function viewAccount(User $user, Account $account): bool
    {
        return $user->hasAnyAccountMembership($account) || $this->viewAll($user);
    }
}
