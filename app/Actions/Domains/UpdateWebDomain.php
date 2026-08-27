<?php

namespace App\Actions\Domains;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesWebCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\User;
use App\Models\WebDomain;
use App\Models\WebDomainAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UpdateWebDomain
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{domain: string, web_template?: string, ssl_mode?: string, aliases?: array<int, string>}
     */
    public function handle(User $actor, WebDomain $webDomain, array $data): WebDomain
    {
        Gate::forUser($actor)->authorize('update', $webDomain);

        return DB::transaction(function () use ($actor, $webDomain, $data): WebDomain {
            $webDomain->forceFill([
                'domain' => WebDomain::normalizeDomain($data['domain']),
                'web_template' => $data['web_template'] ?? 'default',
                'ssl_mode' => $data['ssl_mode'] ?? 'none',
                'desired_state_version' => $webDomain->desired_state_version + 1,
            ])->save();

            $webDomain->aliases()->delete();

            foreach ($data['aliases'] ?? [] as $alias) {
                WebDomainAlias::query()->create([
                    'web_domain_id' => $webDomain->id,
                    'alias' => WebDomain::normalizeDomain($alias),
                ]);
            }

            $capability = app(ResolvesWebCapableNode::class)->resolveFor($webDomain->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $webDomain->getMorphClass(),
                'auditable_id' => $webDomain->getKey(),
                'action' => 'web_domain.updated',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $webDomain,
                $capability,
                ProvisioningVerb::Update,
                $webDomain->toProvisioningPayload(),
                $correlationId,
                $webDomain->desired_state_version,
            );

            return $webDomain;
        });
    }
}
