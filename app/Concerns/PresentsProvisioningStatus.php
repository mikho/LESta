<?php

namespace App\Concerns;

use App\Models\ProvisioningOperation;

/**
 * A resource's latest provisioning operation, shaped the same way for every customer page: the
 * status, the first reason the node gave when it could not apply the change, and when that
 * operation last moved, so the page can say what is happening and why.
 */
trait PresentsProvisioningStatus
{
    /**
     * @return array{provisioning_status: string|null, provisioning_error: string|null, provisioning_updated_at: string|null}
     */
    protected function provisioningProps(?ProvisioningOperation $operation): array
    {
        $message = $operation?->errors[0]['message'] ?? null;

        return [
            'provisioning_status' => $operation?->status->value,
            'provisioning_error' => is_string($message) ? $message : null,
            'provisioning_updated_at' => $operation === null ? null : ($operation->completed_at ?? $operation->dispatched_at ?? $operation->issued_at)->toIso8601String(),
        ];
    }
}
