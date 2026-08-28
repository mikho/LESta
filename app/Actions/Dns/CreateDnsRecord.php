<?php

namespace App\Actions\Dns;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesDnsCapableNode;
use App\Enums\ProvisioningVerb;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\AuditEvent;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use App\Models\PackageLimit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateDnsRecord
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{name: string, type: string, priority?: int|null, value: string}
     */
    public function handle(User $actor, DnsZone $dnsZone, array $data): DnsRecord
    {
        Gate::forUser($actor)->authorize('create', [DnsRecord::class, $dnsZone]);

        return DB::transaction(function () use ($actor, $dnsZone, $data): DnsRecord {
            $limit = PackageLimit::query()
                ->where('package_id', $dnsZone->account->package_id)
                ->where('resource_type', 'dns_records')
                ->first();

            if ($limit === null) {
                throw ResourceQuotaExceededException::notConfigured('dns_records');
            }

            if ($limit->limit_value !== null && $dnsZone->records()->count() >= $limit->limit_value) {
                throw ResourceQuotaExceededException::limitReached('dns_records', $limit->limit_value);
            }

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
