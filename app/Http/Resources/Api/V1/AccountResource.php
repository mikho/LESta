<?php

namespace App\Http\Resources\Api\V1;

use App\Models\Account;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Account
 */
class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'contact_email' => $this->contact_email,
            'package' => $this->package === null ? null : ['id' => $this->package->uuid, 'name' => $this->package->name],
            'is_owner' => $request->user()->hasAccountRole($this->resource, 'owner'),
            'reseller_account_id' => $this->resellerAccount?->public_id,
            'suspended' => $this->isSuspended(),
            'suspended_at' => $this->suspended_at?->toIso8601ZuluString(),
            'memberships_count' => $this->whenCounted('memberships'),
            'created_at' => $this->created_at?->toIso8601ZuluString(),
        ];
    }
}
