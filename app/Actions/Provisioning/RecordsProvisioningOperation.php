<?php

namespace App\Actions\Provisioning;

use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Jobs\DispatchProvisioningOperation;
use App\Models\ProvisioningOperation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class RecordsProvisioningOperation
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(
        Model $provisionable,
        string $capability,
        ProvisioningVerb $operation,
        array $payload,
        string $correlationId,
        int $desiredStateVersion = 1,
    ): ProvisioningOperation {
        // Resource-agnostic action: $provisionable is expected to expose a `uuid` attribute
        // (Account does; Phase 2's WebDomain etc. will too), but the bare Model type-hint has
        // no such property.
        $resourceId = $provisionable->uuid; // @phpstan-ignore property.notFound

        $row = ProvisioningOperation::create([
            'provisionable_type' => $provisionable->getMorphClass(),
            'provisionable_id' => $provisionable->getKey(),
            'resource_id' => $resourceId,
            'capability' => $capability,
            'operation' => $operation,
            'status' => ProvisioningStatus::Pending,
            'desired_state_version' => $desiredStateVersion,
            'payload' => $payload,
            'correlation_id' => $correlationId,
            'idempotency_key' => (string) Str::uuid(),
            'issued_at' => now(),
            'request_digest' => 'sha256:'.hash('sha256', json_encode([
                $capability, $operation->value, $resourceId, $desiredStateVersion, $payload,
            ], JSON_THROW_ON_ERROR)),
        ]);

        DispatchProvisioningOperation::dispatch($row->id)->afterCommit();

        return $row;
    }
}
