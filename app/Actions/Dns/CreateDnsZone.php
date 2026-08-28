<?php

namespace App\Actions\Dns;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesDnsCapableNode;
use App\Enums\ProvisioningVerb;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\DnsZone;
use App\Models\PackageLimit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateDnsZone
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{domain: string, ttl?: int}
     */
    public function handle(User $actor, Account $account, array $data): DnsZone
    {
        Gate::forUser($actor)->authorize('create', [DnsZone::class, $account]);

        return DB::transaction(function () use ($actor, $account, $data): DnsZone {
            $limit = PackageLimit::query()
                ->where('package_id', $account->package_id)
                ->where('resource_type', 'dns_zones')
                ->first();

            if ($limit === null) {
                throw ResourceQuotaExceededException::notConfigured('dns_zones');
            }

            if ($limit->limit_value !== null && $account->dnsZones()->count() >= $limit->limit_value) {
                throw ResourceQuotaExceededException::limitReached('dns_zones', $limit->limit_value);
            }

            [$node, $capability] = app(ResolvesDnsCapableNode::class)->resolve();

            $dnsZone = DnsZone::query()->create([
                'account_id' => $account->id,
                'node_id' => $node->id,
                'domain' => DnsZone::normalizeDomain($data['domain']),
                'ttl' => $data['ttl'] ?? 14400,
                'desired_state_version' => 1,
            ]);

            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $dnsZone->getMorphClass(),
                'auditable_id' => $dnsZone->getKey(),
                'action' => 'dns_zone.created',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $dnsZone,
                $capability,
                ProvisioningVerb::Create,
                $dnsZone->toProvisioningPayload(),
                $correlationId,
                1,
            );

            return $dnsZone;
        });
    }
}
