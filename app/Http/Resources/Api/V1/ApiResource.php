<?php

namespace App\Http\Resources\Api\V1;

use App\Enums\SuspensionSource;
use App\Models\ProvisioningOperation;
use Carbon\CarbonInterface;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * The shapes every API resource shares: timestamps in ISO 8601 UTC, the suspension fields, and the
 * state of the latest change sent to the node.
 */
abstract class ApiResource extends JsonResource
{
    protected function timestamp(?CarbonInterface $value): ?string
    {
        return $value?->toIso8601ZuluString();
    }

    /**
     * @return array{suspended: bool, suspended_at: string|null, suspension_source: string|null}
     */
    protected function suspension(?CarbonInterface $suspendedAt, ?SuspensionSource $source): array
    {
        return [
            'suspended' => $suspendedAt !== null,
            'suspended_at' => $this->timestamp($suspendedAt),
            'suspension_source' => $source?->value,
        ];
    }

    /**
     * The latest provisioning operation's status (pending, dispatched, applied, already_applied,
     * rejected, failed or degraded) and when the node finished it. Null when nothing was ever sent.
     *
     * @return array{status: string, completed_at: string|null}|null
     */
    protected function provisioning(?ProvisioningOperation $operation): ?array
    {
        return $operation === null ? null : [
            'status' => $operation->status->value,
            'completed_at' => $this->timestamp($operation->completed_at),
        ];
    }
}
