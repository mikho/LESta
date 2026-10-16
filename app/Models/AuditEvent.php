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
 * @property int|null $api_token_id
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
            // A change made through the API is stamped with the token it came through, and noted
            // on the request so the API can answer with the change's operation id.
            $token = request()->attributes->get('api_token');

            if ($token instanceof ApiToken) {
                $event->api_token_id ??= $token->id;
                request()->attributes->set('api_audit_events', [...request()->attributes->get('api_audit_events', []), $event]);
            }

            $event->previous_hash = static::query()->orderByDesc('id')->value('hash');
            $event->hash = $event->chainHash($event->previous_hash);
        });
    }

    /**
     * The row's hash in the chain, from the previous row's hash and this row's own fields. Shared
     * with audit:verify-chain. The API token id is part of it only when set, so rows written before
     * the column existed still verify.
     */
    public function chainHash(?string $previousHash): string
    {
        $fields = [
            (string) $previousHash,
            (string) $this->actor_type,
            (string) $this->actor_id,
            (string) $this->auditable_type,
            (string) $this->auditable_id,
            (string) $this->action,
            (string) $this->correlation_id,
            (string) ($this->getAttributes()['metadata'] ?? null),
        ];

        if ($this->api_token_id !== null) {
            $fields[] = 'api_token:'.$this->api_token_id;
        }

        return hash('sha256', implode('|', $fields));
    }
}
