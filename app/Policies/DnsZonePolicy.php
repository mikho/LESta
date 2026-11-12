<?php

namespace App\Policies;

class DnsZonePolicy extends AccountOwnedResourcePolicy
{
    protected function viewAnyPermission(): string
    {
        return 'dns_zones.view_any';
    }
}
