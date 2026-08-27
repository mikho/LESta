<?php

namespace App\Models;

use App\Enums\IdempotencyReceiptStatus;
use Database\Factories\IdempotencyReceiptFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $scope
 * @property string $idempotency_key
 * @property IdempotencyReceiptStatus $status
 * @property string|null $actor_type
 * @property int|string|null $actor_id
 * @property string $correlation_id
 * @property array<string, mixed>|null $response
 * @property Carbon|null $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['scope', 'idempotency_key', 'status', 'actor_type', 'actor_id', 'correlation_id', 'response', 'expires_at'])]
class IdempotencyReceipt extends Model
{
    /** @use HasFactory<IdempotencyReceiptFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => IdempotencyReceiptStatus::class,
            'response' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function actor(): MorphTo
    {
        return $this->morphTo();
    }
}
