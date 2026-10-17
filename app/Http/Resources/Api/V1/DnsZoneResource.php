<?php

namespace App\Http\Resources\Api\V1;

use App\Models\DnsZone;
use Illuminate\Http\Request;

/**
 * @mixin DnsZone
 */
class DnsZoneResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'account_id' => $this->whenLoaded('account', fn (): string => $this->account->public_id),
            'domain' => $this->domain,
            'ttl' => $this->ttl,
            'records_count' => $this->whenCounted('records'),
            ...$this->suspension($this->suspended_at, $this->suspension_source),
            'provisioning' => $this->provisioning($this->latestProvisioningOperation),
            'created_at' => $this->timestamp($this->created_at),
        ];
    }
}
