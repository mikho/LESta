<?php

namespace App\Http\Resources\Api\V1;

use App\Models\AccountBackup;
use Illuminate\Http\Request;

/**
 * An account backup. Never the encryption key or the artifact's path on the node.
 *
 * @mixin AccountBackup
 */
class AccountBackupResource extends ApiResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->uuid,
            'label' => $this->label,
            'kind' => $this->kind,
            'node' => $this->node->name,
            'status' => $this->state(),
            'error' => $this->error_message,
            'parts' => $this->parts ?? $this->requested_parts,
            'size_bytes' => $this->size_bytes,
            'skipped' => $this->report['skipped'] ?? [],
            'remote' => $this->remote_status === null ? null : [
                'status' => $this->remote_status,
                'key' => $this->remote_key,
                'at' => $this->timestamp($this->remote_at),
                'error' => $this->remote_error,
            ],
            'last_restore' => $this->last_restore_status === null ? null : [
                'status' => $this->last_restore_status,
                'at' => $this->timestamp($this->last_restore_at),
                'restored' => $this->last_restore_report['restored'] ?? [],
                'skipped' => $this->last_restore_report['skipped'] ?? [],
                'error' => $this->last_restore_error,
            ],
            'completed_at' => $this->timestamp($this->completed_at),
            'created_at' => $this->timestamp($this->created_at),
        ];
    }
}
