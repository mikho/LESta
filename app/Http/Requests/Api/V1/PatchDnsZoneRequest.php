<?php

namespace App\Http\Requests\Api\V1;

use App\Http\Requests\Dns\UpdateDnsZoneRequest;
use App\Models\DnsZone;

/**
 * The panel's update rules, for a PATCH: every field left out keeps its current value.
 */
class PatchDnsZoneRequest extends UpdateDnsZoneRequest
{
    protected function prepareForValidation(): void
    {
        /** @var DnsZone $dnsZone */
        $dnsZone = $this->route('dnsZone');

        $this->merge(array_diff_key(['domain' => $dnsZone->domain, 'ttl' => $dnsZone->ttl], $this->all()));

        parent::prepareForValidation();
    }
}
