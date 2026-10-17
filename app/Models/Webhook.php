<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Database\Factories\WebhookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A URL LESta calls when something finishes: an account's own (account_id set) or platform-wide
 * (account_id null, every account and node). Each delivery is signed with the webhook's secret,
 * which is stored encrypted and shown once. See the API Guide's webhooks chapter.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $account_id
 * @property int|null $created_by
 * @property string $url
 * @property string $secret
 * @property list<string> $events
 * @property string|null $description
 * @property int $consecutive_failures
 * @property Carbon|null $disabled_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['account_id', 'created_by', 'url', 'secret', 'events', 'description'])]
#[Hidden(['secret'])]
class Webhook extends Model
{
    /** @use HasFactory<WebhookFactory> */
    use HasFactory, HasUuid;

    /** What a webhook can be sent for. "ping" is the test a webhook's page sends by hand. */
    public const array EVENTS = ['operation.finished', 'backup.finished', 'restore.finished'];

    /** A webhook switches itself off after this many deliveries in a row failed for good. */
    public const int DISABLE_AFTER_FAILURES = 10;

    public const string SECRET_PREFIX = 'whsec_';

    protected function casts(): array
    {
        return [
            'secret' => 'encrypted',
            'events' => 'array',
            'disabled_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    public static function generateSecret(): string
    {
        return self::SECRET_PREFIX.Str::random(40);
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<WebhookDelivery, $this>
     */
    public function deliveries(): HasMany
    {
        return $this->hasMany(WebhookDelivery::class);
    }

    public function isPlatformWide(): bool
    {
        return $this->account_id === null;
    }

    public function isDisabled(): bool
    {
        return $this->disabled_at !== null;
    }
}
