<?php

namespace App\Actions\ApiTokens;

use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Creates a personal API token for the acting user. The token can only be narrower than its user:
 * the admin abilities need a platform user, and restricting it to an account needs ownership of that
 * account.
 */
class CreateApiToken
{
    /**
     * @param  array{name: string, abilities: list<string>, account_id?: int|null, expires_in_days?: int|null}  $data
     * @return array{0: ApiToken, 1: string} the token and its plaintext, shown once
     */
    public function handle(User $actor, array $data): array
    {
        Gate::forUser($actor)->authorize('create', ApiToken::class);

        $abilities = array_values(array_unique($data['abilities']));

        if (array_intersect($abilities, ApiToken::ADMIN_ABILITIES) !== [] && ! $actor->isPlatformUser()) {
            throw ValidationException::withMessages(['abilities' => __('Only platform users can give a token the admin abilities.')]);
        }

        $account = null;

        if (($data['account_id'] ?? null) !== null) {
            $account = Account::query()->find($data['account_id']);

            if ($account === null || ! $actor->hasAccountRole($account, 'owner')) {
                throw ValidationException::withMessages(['account_id' => __('You can only restrict a token to an account you own.')]);
            }
        }

        $plaintext = ApiToken::generatePlaintext();

        $token = DB::transaction(function () use ($actor, $data, $abilities, $account, $plaintext): ApiToken {
            $token = ApiToken::query()->create([
                'user_id' => $actor->id,
                'account_id' => $account?->id,
                'name' => $data['name'],
                'token_hash' => ApiToken::hashToken($plaintext),
                'abilities' => $abilities,
                'expires_at' => now()->addDays($data['expires_in_days'] ?? ApiToken::DEFAULT_DAYS),
            ]);

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $token->getMorphClass(),
                'auditable_id' => $token->getKey(),
                'action' => 'api_token.created',
                'correlation_id' => (string) Str::uuid(),
            ]);

            return $token;
        });

        return [$token, $plaintext];
    }
}
