<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\ProvisioningStatus;
use App\Models\Backup;
use Illuminate\Http\Request;

/**
 * A whole-node backup. Never its encryption key or the artifact's path on the node.
 *
 * @mixin Backup
 */
class NodeBackupResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'label' => $this->label,
            'node' => ['id' => $this->node->uuid, 'name' => $this->node->name],
            'status' => match (true) {
                in_array($this->status, [ProvisioningStatus::Applied, ProvisioningStatus::AlreadyApplied], true) => 'ready',
                in_array($this->status, [ProvisioningStatus::Failed, ProvisioningStatus::Rejected, ProvisioningStatus::Degraded], true) => 'failed',
                default => 'running',
            },
            'error' => $this->error_message,
            'included_capabilities' => $this->included_capabilities ?? [],
            'size_bytes' => $this->size_bytes,
            'completed_at' => $this->timestamp($this->completed_at),
            'created_at' => $this->timestamp($this->created_at),
        ];
    }
}
