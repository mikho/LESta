<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Database\Factories\WebhookDeliveryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One event sent (or still to be sent) to one webhook. event_key is unique per webhook, so the
 * same event is never queued twice for it, however many node results arrive at once.
 *
 * @property int $id
 * @property string $uuid
 * @property int $webhook_id
 * @property string $event
 * @property string $event_key
 * @property array<string, mixed> $payload
 * @property string $status pending, delivered or failed
 * @property int $attempts
 * @property int|null $response_status
 * @property string|null $error
 * @property Carbon|null $next_attempt_at
 * @property Carbon|null $delivered_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['webhook_id', 'event', 'event_key', 'payload', 'status', 'next_attempt_at'])]
class WebhookDelivery extends Model
{
    /** @use HasFactory<WebhookDeliveryFactory> */
    use HasFactory, HasUuid, MassPrunable;

    /** Seconds to wait before each retry; one try plus these, then the delivery has failed. */
    public const array RETRY_AFTER_SECONDS = [60, 300, 1800, 7200, 21600, 43200];

    /** Deliveries are kept this long for the webhook's page, then pruned. */
    public const int KEEP_DAYS = 30;

    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'next_attempt_at' => 'datetime',
            'delivered_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Webhook, $this>
     */
    public function webhook(): BelongsTo
    {
        return $this->belongsTo(Webhook::class);
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return static::query()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
