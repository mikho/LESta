<?php

namespace App\Models;

use Database\Factories\AuditEventFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string|null $actor_type
 * @property int|string|null $actor_id
 * @property string $auditable_type
 * @property int|string $auditable_id
 * @property string $action
 * @property string $correlation_id
 * @property array<string, mixed>|null $metadata
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $previous_hash
 * @property string|null $hash
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['actor_type', 'actor_id', 'auditable_type', 'auditable_id', 'action', 'correlation_id', 'metadata', 'ip_address', 'user_agent'])]
class AuditEvent extends Model
{
    /** @use HasFactory<AuditEventFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    /**
     * previous_hash/hash are never mass-assignable (deliberately absent from #[Fillable] above)
     * and always computed here, transparently, for every one of this app's ~20 real
     * AuditEvent::create() call sites -- none of them need to know this exists. Altering or
     * deleting any existing row breaks the chain from that point forward, detectable by
     * `php artisan audit:verify-chain`: this table has no route or policy exposing edit/delete
     * through the application today, but nothing at the data layer would otherwise reveal
     * tampering via a compromised DB credential or a future bug that reaches this table directly.
     *
     * Reads metadata's own raw, already-JSON-encoded attribute (getAttributes(), not the
     * decoded-array accessor) so the exact same bytes get hashed here as the migration that
     * originally backfilled every pre-existing row hashed from the raw database column -- and
     * created_at is deliberately excluded from the hashed material, since Model::performInsert()
     * only assigns timestamps AFTER the "creating" event this runs on, so it does not exist yet
     * at this point for a fresh row either.
     */
    protected static function booted(): void
    {
        static::creating(function (self $event): void {
            $previousHash = static::query()->orderByDesc('id')->value('hash');

            $event->previous_hash = $previousHash;
            $event->hash = hash('sha256', implode('|', [
                (string) $previousHash,
                (string) $event->actor_type,
                (string) $event->actor_id,
                (string) $event->auditable_type,
                (string) $event->auditable_id,
                (string) $event->action,
                (string) $event->correlation_id,
                (string) ($event->getAttributes()['metadata'] ?? null),
            ]));
        });
    }
}
