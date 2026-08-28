<?php

namespace App\Actions\Dns;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesDnsCapableNode;
use App\Enums\ProvisioningVerb;
use App\Enums\SuspensionSource;
use App\Models\AuditEvent;
use App\Models\DnsRecord;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SuspendDnsRecord
{
    public function handle(User $actor, DnsRecord $dnsRecord, SuspensionSource $source = SuspensionSource::Manual): void
    {
        Gate::forUser($actor)->authorize('suspend', $dnsRecord);

        if ($dnsRecord->isSuspended()) {
            return; // duplicate submission: no second audit row
        }

        DB::transaction(function () use ($actor, $dnsRecord, $source): void {
            $dnsRecord->suspend($source);

            // Standalone record suspension (not part of a zone cascade): the zone itself does
            // not transition, so this is recorded as an Update against the zone, not a Suspend.
            $dnsZone = $dnsRecord->dnsZone;
            $dnsZone->forceFill(['desired_state_version' => $dnsZone->desired_state_version + 1])->save();

            $capability = app(ResolvesDnsCapableNode::class)->resolveFor($dnsZone->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $dnsRecord->getMorphClass(),
                'auditable_id' => $dnsRecord->getKey(),
                'action' => 'dns_record.suspended',
                'correlation_id' => $correlationId,
                'metadata' => ['source' => $source->value],
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
