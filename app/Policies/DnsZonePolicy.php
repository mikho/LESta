<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\DnsZone;
use App\Models\User;

class DnsZonePolicy
{
    public function viewAny(User $user, Account $account): bool
    {
        return $user->hasAnyAccountMembership($account);
    }

    /**
     * The provider admin's read-only, cross-account list of every customer's resources. Never
     * implies any per-resource ability: viewing a customer's resource, or acting on it, stays
     * member and owner scoped.
     */
    public function viewAnyAcrossAccounts(User $user): bool
    {
        return $user->hasPermission('dns_zones.view_any');
    }

    public function view(User $user, DnsZone $dnsZone): bool
    {
        return $user->hasAnyAccountMembership($dnsZone->account);
    }

    public function create(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner');
    }

    public function update(User $user, DnsZone $dnsZone): bool
    {
        return $user->hasAccountRole($dnsZone->account, 'owner');
    }

    /**
     * Also recognizes accounts.suspend/unsuspend/delete, the exact permission that already
     * authorized the owning Account-level action (SuspendAccount/UnsuspendAccount/DeleteAccount)
     * these three abilities exist to be cascaded from: an admin permitted to suspend/unsuspend/
     * delete a whole account cannot be blocked from the same action's own unavoidable cascade
     * into that account's individual DNS zones, or the top-level action would fail outright the
     * moment any account with real resources hit it. Not a new permission grant, just making the
     * one already given fully work.
     */
    public function suspend(User $user, DnsZone $dnsZone): bool
    {
        return $user->hasAccountRole($dnsZone->account, 'owner') || $user->hasPermission('accounts.suspend');
    }

    public function unsuspend(User $user, DnsZone $dnsZone): bool
    {
        return $user->hasAccountRole($dnsZone->account, 'owner') || $user->hasPermission('accounts.unsuspend');
    }

    public function delete(User $user, DnsZone $dnsZone): bool
    {
        return $user->hasAccountRole($dnsZone->account, 'owner') || $user->hasPermission('accounts.delete');
    }
}
