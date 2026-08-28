<?php

namespace App\Actions\Dns;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesDnsCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\DnsZone;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UpdateDnsZone
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{domain: string, ttl?: int}
     */
    public function handle(User $actor, DnsZone $dnsZone, array $data): DnsZone
    {
        Gate::forUser($actor)->authorize('update', $dnsZone);

        return DB::transaction(function () use ($actor, $dnsZone, $data): DnsZone {
            $dnsZone->forceFill([
                'domain' => DnsZone::normalizeDomain($data['domain']),
                'ttl' => $data['ttl'] ?? $dnsZone->ttl,
                'desired_state_version' => $dnsZone->desired_state_version + 1,
            ])->save();

            $capability = app(ResolvesDnsCapableNode::class)->resolveFor($dnsZone->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $dnsZone->getMorphClass(),
                'auditable_id' => $dnsZone->getKey(),
                'action' => 'dns_zone.updated',
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

            return $dnsZone;
        });
    }
}
