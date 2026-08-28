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

class UnsuspendDnsRecord
{
    public function handle(User $actor, DnsRecord $dnsRecord): void
    {
        Gate::forUser($actor)->authorize('unsuspend', $dnsRecord);

        if (! $dnsRecord->isSuspended()) {
            return;
        }

        DB::transaction(function () use ($actor, $dnsRecord): void {
            $dnsRecord->unsuspend();

            // Standalone record unsuspension: recorded as an Update against the zone, not an
            // Unsuspend, since the zone itself does not transition.
            $dnsZone = $dnsRecord->dnsZone;
            $dnsZone->forceFill(['desired_state_version' => $dnsZone->desired_state_version + 1])->save();

            $capability = app(ResolvesDnsCapableNode::class)->resolveFor($dnsZone->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $dnsRecord->getMorphClass(),
                'auditable_id' => $dnsRecord->getKey(),
                'action' => 'dns_record.unsuspended',
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
        });
    }
}
