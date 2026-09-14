<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\MailDomain;
use App\Models\User;

class MailDomainPolicy
{
    public function viewAny(User $user, Account $account): bool
    {
        return $user->hasAnyAccountMembership($account);
    }

    public function view(User $user, MailDomain $mailDomain): bool
    {
        return $user->hasAnyAccountMembership($mailDomain->account);
    }

    public function create(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner');
    }

    public function update(User $user, MailDomain $mailDomain): bool
    {
        return $user->hasAccountRole($mailDomain->account, 'owner');
    }

    /**
     * Also recognizes accounts.suspend/unsuspend/delete, the exact permission that already
     * authorizes the owning Account-level action (SuspendAccount/UnsuspendAccount/DeleteAccount)
     * these three abilities exist to be cascaded from, mirroring WebDomainPolicy's/
     * DnsZonePolicy's own identical fix from Phase 28: an admin permitted to suspend/unsuspend/
     * delete a whole account cannot be blocked from that same action's own unavoidable cascade
     * into that account's individual mail domains.
     */
    public function suspend(User $user, MailDomain $mailDomain): bool
    {
        return $user->hasAccountRole($mailDomain->account, 'owner') || $user->hasPermission('accounts.suspend');
    }

    public function unsuspend(User $user, MailDomain $mailDomain): bool
    {
        return $user->hasAccountRole($mailDomain->account, 'owner') || $user->hasPermission('accounts.unsuspend');
    }

    public function delete(User $user, MailDomain $mailDomain): bool
    {
        return $user->hasAccountRole($mailDomain->account, 'owner') || $user->hasPermission('accounts.delete');
    }
}
