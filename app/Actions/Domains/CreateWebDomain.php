<?php

namespace App\Actions\Domains;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesWebCapableNode;
use App\Enums\IpAllocationStatus;
use App\Enums\ProvisioningVerb;
use App\Exceptions\NoIpAllocationAvailableException;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\IpAllocation;
use App\Models\PackageLimit;
use App\Models\User;
use App\Models\WebDomain;
use App\Models\WebDomainAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateWebDomain
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{domain: string, web_template?: string, web_server?: string, ssl_mode?: string, aliases?: array<int, string>}
     */
    public function handle(User $actor, Account $account, array $data): WebDomain
    {
        Gate::forUser($actor)->authorize('create', [WebDomain::class, $account]);

        return DB::transaction(function () use ($actor, $account, $data): WebDomain {
            $limit = PackageLimit::query()
                ->where('package_id', $account->package_id)
                ->where('resource_type', 'web_domains')
                ->first();

            if ($limit === null) {
                throw ResourceQuotaExceededException::notConfigured('web_domains');
            }

            if ($limit->limit_value !== null && $account->webDomains()->count() >= $limit->limit_value) {
                throw ResourceQuotaExceededException::limitReached('web_domains', $limit->limit_value);
            }

            [$node, $capabilities] = app(ResolvesWebCapableNode::class)->resolve($data['web_server'] ?? 'nginx');

            $ipAllocation = IpAllocation::query()
                ->where('node_id', $node->id)
                ->where('account_id', $account->id)
                ->where('status', IpAllocationStatus::Dedicated)
                ->first()
                ?? IpAllocation::query()
                    ->where('node_id', $node->id)
                    ->where('status', IpAllocationStatus::Shared)
                    ->first();

            if ($ipAllocation === null) {
                throw new NoIpAllocationAvailableException;
            }

            $webDomain = WebDomain::query()->create([
                'account_id' => $account->id,
                'node_id' => $node->id,
                'ip_allocation_id' => $ipAllocation->id,
                'domain' => WebDomain::normalizeDomain($data['domain']),
                'web_template' => $data['web_template'] ?? 'default',
                'web_server' => $data['web_server'] ?? 'nginx',
                'ssl_mode' => $data['ssl_mode'] ?? 'none',
                'desired_state_version' => 1,
            ]);

            foreach ($data['aliases'] ?? [] as $alias) {
                WebDomainAlias::query()->create([
                    'web_domain_id' => $webDomain->id,
                    'alias' => WebDomain::normalizeDomain($alias),
                ]);
            }

            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $webDomain->getMorphClass(),
                'auditable_id' => $webDomain->getKey(),
                'action' => 'web_domain.created',
                'correlation_id' => $correlationId,
            ]);

            foreach ($capabilities as $capability) {
                app(RecordsProvisioningOperation::class)->record(
                    $webDomain,
                    $capability,
                    ProvisioningVerb::Create,
                    $webDomain->toProvisioningPayload($capability),
                    $correlationId,
                    1,
                );
            }

            return $webDomain;
        });
    }
}
