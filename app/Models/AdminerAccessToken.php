<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A single-use, short-lived (60 second) token minted by
 * App\Actions\TenantDatabases\PrepareAdminerSession and redeemed by
 * App\Http\Middleware\AuthenticateAdminerToken over the internal
 * /internal/adminer-credentials/{token} endpoint. Looked up by hashed-token
 * equality only -- never via route-model binding, the same way Laravel's
 * own password-reset tokens are never route-model-bound -- since a raw
 * token must never appear as a literal {adminerAccessToken} route
 * parameter name a stack trace or log line could echo back.
 *
 * @property int $id
 * @property string $token_hash
 * @property int $tenant_database_id
 * @property Carbon $expires_at
 * @property Carbon|null $used_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['token_hash', 'tenant_database_id', 'expires_at', 'used_at'])]
class AdminerAccessToken extends Model
{
    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<TenantDatabase, $this>
     */
    public function tenantDatabase(): BelongsTo
    {
        return $this->belongsTo(TenantDatabase::class);
    }
}
