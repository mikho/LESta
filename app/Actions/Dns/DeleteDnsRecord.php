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

class DeleteDnsRecord
{
    public function handle(User $actor, DnsRecord $dnsRecord): void
    {
        Gate::forUser($actor)->authorize('delete', $dnsRecord);

        DB::transaction(function () use ($actor, $dnsRecord): void {
            $dnsZone = $dnsRecord->dnsZone;
            $recordId = $dnsRecord->id;
            $recordMorphClass = $dnsRecord->getMorphClass();

            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $recordMorphClass,
                'auditable_id' => $recordId,
                'action' => 'dns_record.deleted',
                'correlation_id' => $correlationId,
            ]);

            $dnsRecord->delete();

            $dnsZone->forceFill(['desired_state_version' => $dnsZone->desired_state_version + 1])->save();

            $capability = app(ResolvesDnsCapableNode::class)->resolveFor($dnsZone->node);

            app(RecordsProvisioningOperation::class)->record(
                $dnsZone,
                $capability,
                ProvisioningVerb::Update,
                $dnsZone->toProvisioningPayload(),
                $correlationId,
                $dnsZone->desired_state_version,
            );
        });
    }
}
