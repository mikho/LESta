<?php

namespace App\Http\Resources\Api\V1;

use App\Models\UsageSnapshot;
use Illuminate\Http\Request;

/**
 * One collection cycle's reading for one resource: disk is the size at that moment, requests and
 * bytes sent are what happened since the previous cycle.
 *
 * @mixin UsageSnapshot
 */
class UsageSnapshotResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'resource' => ['type' => $this->resourceType(), 'label' => $this->resourceLabel()],
            'disk_bytes' => $this->disk_bytes,
            'request_count' => $this->request_count,
            'bytes_sent' => $this->bytes_sent,
            'collected_at' => $this->timestamp($this->collected_at),
        ];
    }
}
