<?php

namespace App\Actions\ApiTokens;

use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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
            AuditEvent::record($token, 'api_token.revoked', $actor);

            $token->delete();
        });
    }
}
