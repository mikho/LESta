<?php

namespace App\Actions\Domains;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesWebCapableNode;
use App\Enums\ProvisioningVerb;
use App\Exceptions\NoPhpCapableNodeAvailableException;
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
     * @param  array<string, mixed>  $data  Expected shape: array{domain: string, web_template?: string, web_server?: string, php_version?: string|null, ssl_mode?: string, waf_mode?: string, waf_excluded_rules?: array<int, int>, aliases?: array<int, string>}
     */
    public function handle(User $actor, WebDomain $webDomain, array $data): WebDomain
    {
        Gate::forUser($actor)->authorize('update', $webDomain);

        return DB::transaction(function () use ($actor, $webDomain, $data): WebDomain {
            $previousPhpVersion = $webDomain->php_version;

            $webDomain->forceFill([
                'domain' => WebDomain::normalizeDomain($data['domain']),
                'web_template' => $data['web_template'] ?? 'default',
                'web_server' => $data['web_server'] ?? 'nginx',
                'php_version' => $data['php_version'] ?? null,
                'ssl_mode' => $data['ssl_mode'] ?? 'none',
                'waf_mode' => $data['waf_mode'] ?? $webDomain->waf_mode,
                'waf_excluded_rules' => array_key_exists('waf_excluded_rules', $data) ? array_values($data['waf_excluded_rules']) : $webDomain->waf_excluded_rules,
                'desired_state_version' => $webDomain->desired_state_version + 1,
            ])->save();

            $webDomain->aliases()->delete();

            foreach ($data['aliases'] ?? [] as $alias) {
                WebDomainAlias::query()->create([
                    'web_domain_id' => $webDomain->id,
                    'alias' => WebDomain::normalizeDomain($alias),
                ]);
            }

            $capabilities = app(ResolvesWebCapableNode::class)->resolveFor($webDomain->node, $data['web_server'] ?? 'nginx');
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $webDomain->getMorphClass(),
                'auditable_id' => $webDomain->getKey(),
                'action' => 'web_domain.updated',
                'correlation_id' => $correlationId,
            ]);

            foreach ($capabilities as $capability) {
                app(RecordsProvisioningOperation::class)->record(
                    $webDomain,
                    $capability,
                    ProvisioningVerb::Update,
                    $webDomain->toProvisioningPayload($capability),
                    $correlationId,
                    $webDomain->desired_state_version,
                );
            }

            // web.php-fpm.v1's own verb depends on the transition, since it is a genuinely
            // separate resource generation on the agent side, scoped per this domain's own
            // resource_id: turning PHP on for the first time is that resource's own Create (no
            // prior generation exists for it yet, even though the domain itself already has
            // other capabilities' own generations); turning it off is a Delete (removes the
            // pool fragment); changing version while already on is an ordinary Update.
            $hadPhp = $previousPhpVersion !== null;
            $hasPhp = $webDomain->php_version !== null;

            if ($hadPhp || $hasPhp) {
                $phpCapable = $webDomain->node->capabilities()
                    ->where('capability', 'web.php-fpm.v1')
                    ->whereNull('suspended_at')
                    ->exists();

                if (! $phpCapable) {
                    throw new NoPhpCapableNodeAvailableException;
                }

                if (! $hadPhp) {
                    $verb = ProvisioningVerb::Create;
                } elseif ($hasPhp) {
                    $verb = ProvisioningVerb::Update;
                } else {
                    $verb = ProvisioningVerb::Delete;
                }

                $payload = $hasPhp
                    ? $webDomain->toPhpFpmProvisioningPayload()
                    : $webDomain->toPhpFpmProvisioningPayload($previousPhpVersion);

                app(RecordsProvisioningOperation::class)->record(
                    $webDomain,
                    'web.php-fpm.v1',
                    $verb,
                    $payload,
                    $correlationId,
                    $webDomain->desired_state_version,
                );
            }

            return $webDomain;
        });
    }
}
