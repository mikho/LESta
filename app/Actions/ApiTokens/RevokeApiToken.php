<?php

namespace App\Actions\ApiTokens;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Revokes one of the acting user's API tokens. The row goes; a script still holding the token gets
 * 401 from its next call.
 */
class RevokeApiToken
{
    public function handle(User $actor, ApiToken $token): void
    {
        Gate::forUser($actor)->authorize('delete', $token);

        DB::transaction(function () use ($actor, $token): void {
            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $token->getMorphClass(),
                'auditable_id' => $token->getKey(),
                'action' => 'api_token.revoked',
                'correlation_id' => (string) Str::uuid(),
            ]);

            $token->delete();
        });
    }
}
