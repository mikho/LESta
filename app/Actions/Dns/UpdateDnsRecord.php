<?php

namespace App\Actions\Dns;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesDnsCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\DnsRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UpdateDnsRecord
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{name: string, type: string, priority?: int|null, value: string}
     */
    public function handle(User $actor, DnsRecord $dnsRecord, array $data): DnsRecord
    {
        Gate::forUser($actor)->authorize('update', $dnsRecord);

        return DB::transaction(function () use ($actor, $dnsRecord, $data): DnsRecord {
            $dnsRecord->forceFill([
                'name' => $data['name'] ?? $dnsRecord->name,
                'type' => $data['type'] ?? $dnsRecord->type,
                'priority' => array_key_exists('priority', $data) ? $data['priority'] : $dnsRecord->priority,
                'value' => $data['value'] ?? $dnsRecord->value,
            ])->save();

            $dnsZone = $dnsRecord->dnsZone;
            $dnsZone->forceFill(['desired_state_version' => $dnsZone->desired_state_version + 1])->save();

            $capability = app(ResolvesDnsCapableNode::class)->resolveFor($dnsZone->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $dnsRecord->getMorphClass(),
                'auditable_id' => $dnsRecord->getKey(),
                'action' => 'dns_record.updated',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $dnsZone,
                $capability,
                ProvisioningVerb::Update,
                $dnsZone->toProvisioningPayload(),
                $correlationId,
                $dnsZone->desired_state_version,
            );

            return $dnsRecord;
        });
    }
}
