<?php

namespace App\Actions\Domains;

use App\Actions\Provisioning\EnsuresAccountNodeIdentity;
use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesWebCapableNode;
use App\Concerns\EnforcesPackageQuota;
use App\Enums\IpAllocationStatus;
use App\Enums\ProvisioningVerb;
use App\Exceptions\NoIpAllocationAvailableException;
use App\Exceptions\NoPhpCapableNodeAvailableException;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\IpAllocation;
use App\Models\User;
use App\Models\WebDomain;
use App\Models\WebDomainAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateWebDomain
{
    use EnforcesPackageQuota;

    /**
     * @param  array<string, mixed>  $data  Expected shape: array{domain: string, web_template?: string, web_server?: string, php_version?: string|null, ssl_mode?: string, aliases?: array<int, string>}
     */
    public function handle(User $actor, Account $account, array $data): WebDomain
    {
        Gate::forUser($actor)->authorize('create', [WebDomain::class, $account]);

        return DB::transaction(function () use ($actor, $account, $data): WebDomain {
            $this->assertPackageQuotaAvailable($account, $account, 'web_domains', fn () => $account->webDomains()->count());

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

            // Lazily ensure this account's own dedicated, per-node Linux system user exists on
            // $node -- the same prerequisite CreateCronJob already ensures, now also needed here
            // since a web domain's own future SFTP access (Web Application Hosting Threat Model
            // and Isolation Design.md) is provisioned against this exact identity. Dispatches
            // independently of the web domain's own provisioning operations below (eventual
            // consistency by design, matching CreateCronJob's own established pattern).
            app(EnsuresAccountNodeIdentity::class)->handle($account, $node);

            $webDomain = WebDomain::query()->create([
                'account_id' => $account->id,
                'node_id' => $node->id,
                'ip_allocation_id' => $ipAllocation->id,
                'domain' => WebDomain::normalizeDomain($data['domain']),
                'web_template' => $data['web_template'] ?? 'default',
                'web_server' => $data['web_server'] ?? 'nginx',
                'php_version' => $data['php_version'] ?? null,
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

            // web.php-fpm.v1 is additive alongside whichever web-server capability(s) just
            // recorded above, never a replacement for one -- a domain always needs a real web
            // server capability to serve it; PHP execution is an extra capability on the same
            // node, gated on php_version actually being set and that node genuinely having
            // web.php-fpm.v1 active (never silently skipped, matching every other
            // NoXCapableNodeAvailableException in this codebase).
            if ($webDomain->php_version !== null) {
                $phpCapable = $node->capabilities()
                    ->where('capability', 'web.php-fpm.v1')
                    ->whereNull('suspended_at')
                    ->exists();

                if (! $phpCapable) {
                    throw new NoPhpCapableNodeAvailableException;
                }

                app(RecordsProvisioningOperation::class)->record(
                    $webDomain,
                    'web.php-fpm.v1',
                    ProvisioningVerb::Create,
                    $webDomain->toPhpFpmProvisioningPayload(),
                    $correlationId,
                    1,
                );
            }

            return $webDomain;
        });
    }
}
