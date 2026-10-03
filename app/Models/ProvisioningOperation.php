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
 * @property array<string, mixed>|null $data
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
            // Regularly carries real secrets on the way to a node (a backup's AES-256-GCM key,
            // an ACME-issued certificate's private key, a freshly generated mailbox password),
            // so it gets the same `encrypted` treatment as every one of those secrets' own
            // primary storage column (Backup::encryption_key, AcmeAccount::account_key,
            // MailAccount::password) -- see the migration that widened this column from json to
            // text for why a native JSON column can't hold an encrypted value.
            'payload' => 'encrypted:array',
            'errors' => 'array',
            'data' => 'array',
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

    /**
     * Shape this row as the wire OperationEnvelope a node's agent daemon expects, matching
     * docs/protocol/operation-envelope.schema.json exactly. Shared by AgentHeartbeatController's
     * own general pending-operations list and AgentFileOperationController's own dedicated
     * files.manager.v1 fast-lane poll -- both present the identical wire shape, just filtered to
     * a different query.
     *
     * @return array<string, mixed>
     */
    public function toEnvelopeArray(): array
    {
        return [
            'protocol_version' => $this->protocol_version,
            'capability' => $this->capability,
            'operation' => $this->operation->value,
            'resource_id' => $this->resource_id,
            'desired_state_version' => $this->desired_state_version,
            'idempotency_key' => $this->idempotency_key,
            'correlation_id' => $this->correlation_id,
            'deadline' => $this->deadline?->toIso8601String(),
            'issued_at' => $this->issued_at->toIso8601String(),
            'request_digest' => $this->request_digest,
            'payload' => $this->payload,
        ];
    }
}
