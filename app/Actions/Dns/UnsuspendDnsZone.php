<?php

namespace App\Actions\Dns;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesDnsCapableNode;
use App\Enums\ProvisioningVerb;
use App\Enums\SuspensionSource;
use App\Models\AuditEvent;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UnsuspendDnsZone
{
    public function handle(User $actor, DnsZone $dnsZone): void
    {
        Gate::forUser($actor)->authorize('unsuspend', $dnsZone);

        if (! $dnsZone->isSuspended()) {
            return;
        }

        DB::transaction(function () use ($actor, $dnsZone): void {
            $dnsZone->unsuspend();
            $dnsZone->forceFill(['desired_state_version' => $dnsZone->desired_state_version + 1])->save();

            // Reactivate only cascade-sourced records; a manually-suspended record stays
            // suspended through a zone-level unsuspend.
            $recordsUnsuspended = $dnsZone->records()->where('suspension_source', SuspensionSource::Cascade)->get()
                ->each(fn (DnsRecord $r) => $r->unsuspend())
                ->count();

            $capability = app(ResolvesDnsCapableNode::class)->resolveFor($dnsZone->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $dnsZone->getMorphClass(),
                'auditable_id' => $dnsZone->getKey(),
                'action' => 'dns_zone.unsuspended',
                'correlation_id' => $correlationId,
                'metadata' => ['records_unsuspended' => $recordsUnsuspended],
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $dnsZone,
                $capability,
                ProvisioningVerb::Unsuspend,
                $dnsZone->toProvisioningPayload(),
                $correlationId,
                $dnsZone->desired_state_version,
            );
        });
    }
}
