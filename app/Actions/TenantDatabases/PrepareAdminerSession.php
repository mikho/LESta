<?php

namespace App\Actions\TenantDatabases;

use App\Models\AdminerAccessToken;
use App\Models\AuditEvent;
use App\Models\TenantDatabase;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Mints a single-use, 60-second token and returns the redirect URL a browser follows straight
 * onto the tenant's own domain's /__lesta-adminer__ location (see
 * agent/internal/capability/nginx/templates/php.conf.tmpl). The token itself is redeemed by
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

        $webDomain = WebDomain::query()->adminerEligibleFor($tenantDatabase)->first();

        if ($webDomain === null) {
            throw ValidationException::withMessages([
                'tenant_database' => 'No eligible domain (PHP enabled, certificate issued, served by nginx) exists on this database\'s own node to open Adminer through.',
            ]);
        }

        return DB::transaction(function () use ($actor, $tenantDatabase, $webDomain): string {
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

            return "https://{$webDomain->domain}/__lesta-adminer__?token={$raw}";
        });
    }
}
