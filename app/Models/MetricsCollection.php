<?php

namespace App\Models;

use App\Concerns\HasUuid;
use App\Enums\ProvisioningStatus;
use Database\Factories\MetricsCollectionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Support\Carbon;

/**
 * One real node-scoped observe cycle for metrics.usage.v1: system-triggered (a scheduled command,
 * never a user action -- see App\Console\Commands\CollectUsageMetrics), this row exists purely as
 * the provisionable anchor RecordsProvisioningOperation needs, mirroring Backup's own node-scoped
 * shape. RecordsUsageSnapshot (the completion hook) reads the resulting operation's own
 * ResultEnvelope.data to populate real UsageSnapshot rows once this reaches a terminal status.
 *
 * @property int $id
 * @property string $uuid
 * @property int $node_id
 * @property ProvisioningStatus $status
 * @property int $desired_state_version
 * @property string|null $error_message
 * @property Carbon|null $completed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['node_id', 'desired_state_version'])]
class MetricsCollection extends Model
{
    /** @use HasFactory<MetricsCollectionFactory> */
    use HasFactory, HasUuid;

    protected function casts(): array
    {
        return [
            'status' => ProvisioningStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * @return MorphOne<ProvisioningOperation, $this>
     */
    public function latestProvisioningOperation(): MorphOne
    {
        return $this->morphOne(ProvisioningOperation::class, 'provisionable')->latestOfMany();
    }

    /**
     * Shape the desired-state payload sent to a provisioner: a manifest of every mail account/
     * tenant database/web-facing vhost on this collection's own node, assembled by the caller
     * (see App\Actions\Metrics\CollectUsageMetrics) from Laravel's own database -- the Go agent
     * has no independent way to discover which accounts exist on a node.
     *
     * @param  array<int, array{resource_uuid: string, domain: string, local_part: string}>  $mailAccounts
     * @param  array<int, array{resource_uuid: string, database_name: string, stats_user: string, stats_password: string}>  $tenantDatabases
     * @param  array<int, array{resource_uuid: string, web_server: string}>  $webResources
     * @return array{mail_accounts: array<int, mixed>, tenant_databases: array<int, mixed>, web_resources: array<int, mixed>}
     */
    public function toProvisioningPayload(array $mailAccounts, array $tenantDatabases, array $webResources): array
    {
        return [
            'mail_accounts' => $mailAccounts,
            'tenant_databases' => $tenantDatabases,
            'web_resources' => $webResources,
        ];
    }
}
