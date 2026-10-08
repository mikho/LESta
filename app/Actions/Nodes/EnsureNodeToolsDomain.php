<?php

namespace App\Actions\Nodes;

use App\Actions\Domains\CreateWebDomain;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\Package;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Makes sure a node's own hostname has a web domain: the one vhost that hosts webmail and Adminer,
 * so those tools open on the node's address and never on a customer's domain. The domain is owned
 * by the hidden platform account, created on first use, so no admin has to hold an account (or a
 * package) just to run a node. Idempotent: an existing web domain for the hostname on this node is
 * returned as it is.
 */
class EnsureNodeToolsDomain
{
    public const PLATFORM_ACCOUNT_NAME = 'LESta platform';

    public function handle(Node $node, string $sslMode = 'lets_encrypt', ?User $actor = null): WebDomain
    {
        return DB::transaction(function () use ($node, $sslMode, $actor): WebDomain {
            $existing = WebDomain::query()
                ->where('node_id', $node->id)
                ->where('domain', WebDomain::normalizeDomain($node->hostname))
                ->first();

            if ($existing !== null) {
                return $existing;
            }

            $webDomain = app(CreateWebDomain::class)->handleSystemInitiated($this->platformAccount(), $node, [
                'domain' => $node->hostname,
                'web_server' => 'nginx',
                'ssl_mode' => $sslMode,
            ]);

            AuditEvent::create([
                'actor_type' => $actor?->getMorphClass(),
                'actor_id' => $actor?->getKey(),
                'auditable_type' => $node->getMorphClass(),
                'auditable_id' => $node->getKey(),
                'action' => 'node.tools_domain_created',
                'correlation_id' => (string) Str::uuid(),
            ]);

            return $webDomain;
        });
    }

    /**
     * The one platform account, created on first use. It needs a package only because every
     * account row does; the package is inactive, so it is never offered when creating an account.
     */
    public function platformAccount(): Account
    {
        $account = Account::query()->where('is_platform', true)->first();

        if ($account !== null) {
            return $account;
        }

        $package = Package::query()->firstOrCreate(
            ['name' => self::PLATFORM_ACCOUNT_NAME],
            ['description' => 'Internal: the package of the hidden platform account. Never offered to customers.', 'is_active' => false],
        );

        $account = Account::query()->create([
            'name' => self::PLATFORM_ACCOUNT_NAME,
            'package_id' => $package->id,
        ]);
        $account->forceFill(['is_platform' => true])->save();

        return $account;
    }
}
