<?php

namespace App\Models;

use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use Database\Factories\ProvisioningOperationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $provisionable_type
 * @property int|string $provisionable_id
 * @property int|null $node_id
 * @property string $resource_id
 * @property string $capability
 * @property ProvisioningVerb $operation
 * @property ProvisioningStatus $status
 * @property int $desired_state_version
 * @property array<string, mixed> $payload
 * @property string $protocol_version
 * @property string $correlation_id
 * @property string $idempotency_key
 * @property Carbon|null $deadline
 * @property Carbon $issued_at
 * @property string $request_digest
 * @property int|null $observed_state_version
 * @property string|null $observed_state_digest
 * @property string|null $generation_id
 * @property array<int, array{code: string, message: string, field?: string|null}>|null $errors
 * @property int $attempts
 * @property Carbon|null $dispatched_at
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['provisionable_type', 'provisionable_id', 'node_id', 'resource_id', 'capability', 'operation', 'status', 'desired_state_version', 'payload', 'correlation_id', 'idempotency_key', 'issued_at', 'request_digest'])]
class ProvisioningOperation extends Model
{
    /** @use HasFactory<ProvisioningOperationFactory> */
    use HasFactory;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'operation' => ProvisioningVerb::class,
            'status' => ProvisioningStatus::class,
            'payload' => 'array',
            'errors' => 'array',
            'issued_at' => 'datetime',
            'deadline' => 'datetime',
            'dispatched_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function provisionable(): MorphTo
    {
        return $this->morphTo();
    }
}
