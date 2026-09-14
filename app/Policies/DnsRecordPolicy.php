<?php

namespace App\Policies;

use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\User;

class DnsRecordPolicy
{
    public function viewAny(User $user, DnsZone $dnsZone): bool
    {
        return $user->hasAnyAccountMembership($dnsZone->account);
    }

    public function view(User $user, DnsRecord $dnsRecord): bool
    {
        return $user->hasAnyAccountMembership($dnsRecord->dnsZone->account);
    }

    public function create(User $user, DnsZone $dnsZone): bool
    {
        return $user->hasAccountRole($dnsZone->account, 'owner');
    }

    public function update(User $user, DnsRecord $dnsRecord): bool
    {
        return $user->hasAccountRole($dnsRecord->dnsZone->account, 'owner');
    }

    public function suspend(User $user, DnsRecord $dnsRecord): bool
    {
        return $user->hasAccountRole($dnsRecord->dnsZone->account, 'owner');
    }

    public function unsuspend(User $user, DnsRecord $dnsRecord): bool
    {
        return $user->hasAccountRole($dnsRecord->dnsZone->account, 'owner');
    }

    public function delete(User $user, DnsRecord $dnsRecord): bool
    {
        return $user->hasAccountRole($dnsRecord->dnsZone->account, 'owner');
    }
}
