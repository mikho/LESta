<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Database\Factories\UsageSnapshotFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One real, incremental usage measurement for a single mail account/tenant database/web domain,
 * recorded by RecordsUsageSnapshot from a completed metrics.usage.v1 observe operation's own
 * ResultEnvelope.data. Immutable once created (never updated, matching Backup's own create-only
 * precedent): a fresh row lands for every collection cycle, and retention pruning (see
 * App\Console\Commands\PruneUsageSnapshots) deletes rows past their own retention window rather
 * than mutating them.
 *
 * Unlike Backup, this is genuinely tenant-visible data (per the governing rewrite plan's own
 * "usage snapshots" as part of the account owner's own web-hosting lifecycle): any member of the
 * owning account can view their own account's snapshots (see UsageSnapshotPolicy), not just a
 * provider admin.
 *
 * @property int $id
 * @property string $uuid
 * @property int $account_id
 * @property int $node_id
 * @property string $snapshotable_type
 * @property int $snapshotable_id
 * @property int|null $disk_bytes
 * @property int|null $request_count
 * @property int|null $bytes_sent
 * @property Carbon $collected_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['account_id', 'node_id', 'snapshotable_type', 'snapshotable_id', 'disk_bytes', 'request_count', 'bytes_sent', 'collected_at'])]
class UsageSnapshot extends Model
{
    /** @use HasFactory<UsageSnapshotFactory> */
    use HasFactory, HasUuid;

    protected function casts(): array
    {
        return [
            'collected_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * @return BelongsTo<Account, $this>
     */
    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /**
     * @return BelongsTo<Node, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(Node::class);
    }

    /**
     * The real resource this snapshot measures: a MailAccount, a TenantDatabase, or a WebDomain.
     *
     * @return MorphTo<Model, $this>
     */
    public function snapshotable(): MorphTo
    {
        return $this->morphTo();
    }
}
