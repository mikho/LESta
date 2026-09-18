<?php

namespace App\Actions\Dns;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesDnsCapableNode;
use App\Concerns\EnforcesPackageQuota;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateDnsRecord
{
    use EnforcesPackageQuota;

    /**
     * @param  array<string, mixed>  $data  Expected shape: array{name: string, type: string, priority?: int|null, value: string}
     */
    public function handle(User $actor, DnsZone $dnsZone, array $data): DnsRecord
    {
        Gate::forUser($actor)->authorize('create', [DnsRecord::class, $dnsZone]);

        return DB::transaction(function () use ($actor, $dnsZone, $data): DnsRecord {
            // Locks the zone, not the account: dns_records is a per-zone quota
            // ($dnsZone->records()->count()), so the zone row is the real concurrency boundary --
            // two concurrent inserts into two DIFFERENT zones on the same account were never
            // counted together in the first place and don't need to serialize against each other.
            $this->assertPackageQuotaAvailable($dnsZone, $dnsZone->account, 'dns_records', fn () => $dnsZone->records()->count());

            $dnsRecord = DnsRecord::query()->create([
                'dns_zone_id' => $dnsZone->id,
                'name' => $data['name'],
                'type' => $data['type'],
                'priority' => $data['priority'] ?? null,
                'value' => $data['value'],
            ]);

            $dnsZone->forceFill(['desired_state_version' => $dnsZone->desired_state_version + 1])->save();

            $capability = app(ResolvesDnsCapableNode::class)->resolveFor($dnsZone->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $dnsRecord->getMorphClass(),
                'auditable_id' => $dnsRecord->getKey(),
                'action' => 'dns_record.created',
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
