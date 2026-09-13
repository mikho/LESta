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
            $this->deleteAndDispatch($dnsRecord, $actor);
        });
    }

    /**
     * The system-initiated counterpart to handle(): App\Console\Commands\RetireOldDkimSelectors
     * deletes a retired DKIM selector's own DNS record on a fixed schedule, not a user action,
     * so there is no actor to authorize against or attribute an AuditEvent to (actor_type/
     * actor_id are nullable for exactly this).
     */
    public function handleSystemInitiated(DnsRecord $dnsRecord): void
    {
        DB::transaction(function () use ($dnsRecord): void {
            $this->deleteAndDispatch($dnsRecord, null);
        });
    }

    private function deleteAndDispatch(DnsRecord $dnsRecord, ?User $actor): void
    {
        $dnsZone = $dnsRecord->dnsZone;
        $recordId = $dnsRecord->id;
        $recordMorphClass = $dnsRecord->getMorphClass();

        $correlationId = (string) Str::uuid();

        AuditEvent::create([
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
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
    }
}
