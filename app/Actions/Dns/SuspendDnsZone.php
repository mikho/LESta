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

class SuspendDnsZone
{
    public function handle(User $actor, DnsZone $dnsZone, SuspensionSource $source = SuspensionSource::Manual): void
    {
        Gate::forUser($actor)->authorize('suspend', $dnsZone);

        if ($dnsZone->isSuspended()) {
            return; // duplicate submission: no second audit row
        }

        DB::transaction(function () use ($actor, $dnsZone, $source): void {
            $dnsZone->suspend($source);
            $dnsZone->forceFill(['desired_state_version' => $dnsZone->desired_state_version + 1])->save();

            // Cascade: only records that are currently active get cascade-suspended. A record
            // already suspended for any reason (including manually) is left untouched, so its
            // existing suspended_at/suspension_source survives a later zone-level unsuspend.
            // This is a raw model-flip cascade (no per-record audit event or provisioning
            // operation), mirroring NodeCapability's cascade in SuspendNode, not WebDomain's
            // per-child-Action-call cascade style.
            $recordsSuspended = $dnsZone->records()->whereNull('suspended_at')->get()
                ->each(fn (DnsRecord $r) => $r->suspend(SuspensionSource::Cascade))
                ->count();

            $capability = app(ResolvesDnsCapableNode::class)->resolveFor($dnsZone->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $dnsZone->getMorphClass(),
                'auditable_id' => $dnsZone->getKey(),
                'action' => 'dns_zone.suspended',
                'correlation_id' => $correlationId,
                'metadata' => ['source' => $source->value, 'records_suspended' => $recordsSuspended],
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $dnsZone,
                $capability,
                ProvisioningVerb::Suspend,
                $dnsZone->toProvisioningPayload(),
                $correlationId,
                $dnsZone->desired_state_version,
            );
        });
    }
}
