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
        // (WebDomain, DnsZone, CronJob, MailDomain, ... every real tenant resource that gets
        // provisioned to a node does; Account itself never does, since an Account is never
        // provisioned as its own capability resource), but the bare Model type-hint has no such
        // property.
        $resourceId = $provisionable->uuid; // @phpstan-ignore property.notFound
        $nodeId = $provisionable->node_id; // @phpstan-ignore property.notFound

        $row = ProvisioningOperation::create([
            'provisionable_type' => $provisionable->getMorphClass(),
            'provisionable_id' => $provisionable->getKey(),
            'node_id' => $nodeId,
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
