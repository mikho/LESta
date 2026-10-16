<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TenantDatabase;
use Illuminate\Http\Request;

/**
 * A customer database. Never the password: that is returned only by the call that creates or
 * rotates it.
 *
 * @mixin TenantDatabase
 */
class TenantDatabaseResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'label' => $this->label,
            'database_name' => $this->database_name,
            'database_user' => $this->database_user,
            ...$this->suspension($this->suspended_at, $this->suspension_source),
            'provisioning' => $this->provisioning($this->latestProvisioningOperation),
            'created_at' => $this->timestamp($this->created_at),
        ];
    }
}
