<?php

namespace App\Actions\Dns;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesDnsCapableNode;
use App\Enums\ProvisioningVerb;
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
            $dnsZone->bumpDesiredStateVersion();

            // Reactivate only cascade-sourced records; a manually-suspended record stays
            // suspended through a zone-level unsuspend.
            $recordsUnsuspended = $dnsZone->records()->suspendedByCascade()->get()
                ->each(fn (DnsRecord $r) => $r->unsuspend())
                ->count();

            $capability = app(ResolvesDnsCapableNode::class)->resolveFor($dnsZone->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::record($dnsZone, 'dns_zone.unsuspended', $actor, $correlationId, ['records_unsuspended' => $recordsUnsuspended]);

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
