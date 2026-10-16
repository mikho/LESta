<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Database\Factories\ApiTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A personal API token: the user acting through a script, narrowed to the token's abilities and,
 * optionally, to one account. Only the SHA-256 of the token is stored (the node-credential pattern);
 * the token is shown once when it is created. Every token expires. See the vault's "Tenant and Admin
 * API Design".
 *
 * @property int $id
 * @property string $uuid
 * @property int $user_id
 * @property int|null $account_id
 * @property string $name
 * @property string $token_hash
 * @property list<string> $abilities
 * @property Carbon $expires_at
 * @property Carbon|null $last_used_at
 * @property string|null $last_used_ip
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['user_id', 'account_id', 'name', 'token_hash', 'abilities', 'expires_at'])]
#[Hidden(['token_hash'])]
class ApiToken extends Model
{
    /** @use HasFactory<ApiTokenFactory> */
    use HasFactory, HasUuid;

    /**
     * Every ability a token can carry: read and write per resource family, read for usage (which
     * nothing writes), and the platform-level admin pair only a platform user may choose.
     */
    public const array ABILITIES = [
        'web:read', 'web:write',
        'dns:read', 'dns:write',
        'mail:read', 'mail:write',
        'databases:read', 'databases:write',
        'cron:read', 'cron:write',
        'backups:read', 'backups:write',
        'usage:read',
        'admin:read', 'admin:write',
    ];

    public const array ADMIN_ABILITIES = ['admin:read', 'admin:write'];

    /** Days a token lives unless the user chooses otherwise, and the most they may choose. */
    public const int DEFAULT_DAYS = 90;

    public const int MAX_DAYS = 365;

    /** Days before expiry the panel starts warning. */
    public const int WARN_DAYS = 14;

    /** Prefix of every token, so a leaked one is recognisable (secret scanners, logs). */
    public const string PREFIX = 'lesta_';

    protected function casts(): array
    {
        return [
            'abilities' => 'array',
            'expires_at' => 'datetime',
            'last_used_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public static function generatePlaintext(): string
    {
        return self::PREFIX.Str::random(48);
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function allows(string $ability): bool
    {
        return in_array($ability, $this->abilities, true);
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }

    /**
     * Whether this token may act on $account: always when it is not restricted to one.
     */
    public function reaches(Account $account): bool
    {
        return $this->account_id === null || $this->account_id === $account->id;
    }
}
