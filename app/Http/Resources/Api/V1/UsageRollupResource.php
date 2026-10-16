<?php

namespace App\Http\Resources\Api\V1;

use App\Models\UsageSnapshotRollup;
use Illuminate\Http\Request;

/**
 * One month for one resource: the last disk reading, and requests and bytes sent summed.
 *
 * @mixin UsageSnapshotRollup
 */
class UsageRollupResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'resource' => ['type' => $this->resourceType(), 'label' => $this->resourceLabel()],
            'period' => $this->period->format('Y-m'),
            'disk_bytes_last' => $this->disk_bytes_last,
            'request_count_sum' => $this->request_count_sum,
            'bytes_sent_sum' => $this->bytes_sent_sum,
        ];
    }
}
