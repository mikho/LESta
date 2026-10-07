<?php

namespace App\Actions\TenantDatabases;

use App\Models\AdminerAccessToken;
use App\Models\AuditEvent;
use App\Models\TenantDatabase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mints a single-use, 60-second token and returns the redirect URL a browser follows straight
 * onto the database's own node's address, its /__lesta-adminer__ location (see
 * agent/internal/capability/nginx/templates/webmail.conf.tmpl, the node tools vhost). Never a
 * customer's domain. The token itself is redeemed by
 * App\Http\Middleware\AuthenticateAdminerToken via the internal, token-only-authenticated
 * /internal/adminer-credentials/{token} endpoint that .install/services/adminer/vendor/
 * adminer-lesta-login.php calls -- this action never talks to a node directly, it only ever
 * prepares the hand-off.
 */
class PrepareAdminerSession
{
    public function handle(User $actor, TenantDatabase $tenantDatabase): string
    {
        Gate::forUser($actor)->authorize('update', $tenantDatabase);

        if ($tenantDatabase->isSuspended()) {
            throw ValidationException::withMessages([
                'tenant_database' => 'A suspended database cannot be opened in Adminer.',
            ]);
        }

        $node = $tenantDatabase->node;

        if (! $node->hasAdminerAvailable()) {
            throw ValidationException::withMessages([
                'tenant_database' => 'Adminer is not available on this database\'s node: its own hostname needs a web domain with an issued certificate, and tools.adminer.v1 must be installed.',
            ]);
        }

        return DB::transaction(function () use ($actor, $tenantDatabase, $node): string {
            $raw = Str::random(64);
            $correlationId = (string) Str::uuid();

            AdminerAccessToken::create([
                'token_hash' => hash('sha256', $raw),
                'tenant_database_id' => $tenantDatabase->id,
                'expires_at' => now()->addSeconds(60),
            ]);

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $tenantDatabase->getMorphClass(),
                'auditable_id' => $tenantDatabase->getKey(),
                'action' => 'tenant_database.adminer_session_prepared',
                'correlation_id' => $correlationId,
            ]);

            return "https://{$node->hostname}/__lesta-adminer__?token={$raw}";
        });
    }
}
