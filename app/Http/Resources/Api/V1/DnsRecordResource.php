<?php

namespace App\Http\Resources\Api\V1;

use App\Models\DnsRecord;
use Illuminate\Http\Request;

/**
 * @mixin DnsRecord
 */
class DnsRecordResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'name' => $this->name,
            'type' => $this->type->value,
            'priority' => $this->priority,
            'value' => $this->value,
            ...$this->suspension($this->suspended_at, $this->suspension_source),
            'created_at' => $this->timestamp($this->created_at),
        ];
    }
}
