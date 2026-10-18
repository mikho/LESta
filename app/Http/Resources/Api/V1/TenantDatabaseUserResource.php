<?php

namespace App\Http\Resources\Api\V1;

use App\Models\TenantDatabaseUser;
use Illuminate\Http\Request;

/**
 * An extra user on a customer database. Never the password: the panel does not keep it, and
 * only the call that creates or rotates it returns it.
 *
 * @mixin TenantDatabaseUser
 */
class TenantDatabaseUserResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'label' => $this->label,
            'username' => $this->username,
            'access' => $this->access->value,
            'created_at' => $this->timestamp($this->created_at),
        ];
    }
}
