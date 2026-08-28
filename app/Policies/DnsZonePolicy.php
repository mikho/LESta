<?php

namespace App\Policies;

use App\Models\Account;
use App\Models\DnsZone;
use App\Models\User;

class DnsZonePolicy
{
    public function viewAny(User $user, Account $account): bool
    {
        return $user->memberships()->where('account_id', $account->id)->exists();
    }

    public function view(User $user, DnsZone $dnsZone): bool
    {
        return $user->memberships()->where('account_id', $dnsZone->account_id)->exists();
    }

    public function create(User $user, Account $account): bool
    {
        return $user->hasAccountRole($account, 'owner');
    }

    public function update(User $user, DnsZone $dnsZone): bool
    {
        return $user->hasAccountRole($dnsZone->account, 'owner');
    }

    public function suspend(User $user, DnsZone $dnsZone): bool
    {
        return $user->hasAccountRole($dnsZone->account, 'owner');
    }

    public function unsuspend(User $user, DnsZone $dnsZone): bool
    {
        return $user->hasAccountRole($dnsZone->account, 'owner');
    }

    public function delete(User $user, DnsZone $dnsZone): bool
    {
        return $user->hasAccountRole($dnsZone->account, 'owner');
    }
}
