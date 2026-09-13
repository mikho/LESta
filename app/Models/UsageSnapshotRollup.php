<?php

namespace App\Models;

use App\Concerns\HasUuid;
use Database\Factories\UsageSnapshotRollupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;

/**
 * One monthly aggregate of UsageSnapshot rows for a single resource, written by
 * App\Console\Commands\RollupUsageSnapshots for every calendar month strictly before the current
 * one that still has raw snapshots present, so a month's rollup is always computed from its own
 * complete set of raw data before those rows age out of UsageSnapshot's 90-day retention window.
 *
 * Unlike UsageSnapshot, a row here is upserted (unique on snapshotable + period), not purely
 * append-only: recomputing an already-finalized month is a safe no-op (same input, same output),
 * which is what makes RollupUsageSnapshots idempotent and safe to run daily. disk_bytes_last takes
 * the most recent raw snapshot's value within the month (a point-in-time gauge, not a delta);
 * request_count_sum/bytes_sent_sum sum every raw snapshot in the month (real deltas). Retained
 * indefinitely -- there is no pruning command for this table, deliberately: the whole point is to
 * survive past raw retention.
 *
 * @property int $id
 * @property string $uuid
 * @property int $account_id
 * @property int $node_id
 * @property string $snapshotable_type
 * @property int $snapshotable_id
 * @property Carbon $period
 * @property int|null $disk_bytes_last
 * @property int|null $request_count_sum
 * @property int|null $bytes_sent_sum
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['account_id', 'node_id', 'snapshotable_type', 'snapshotable_id', 'period', 'disk_bytes_last', 'request_count_sum', 'bytes_sent_sum'])]
class UsageSnapshotRollup extends Model
{
    /** @use HasFactory<UsageSnapshotRollupFactory> */
    use HasFactory, HasUuid;

    protected function casts(): array
    {
        return [
            // date:Y-m-d, not the bare 'date' cast: the latter still stores a full
            // "Y-m-d 00:00:00" datetime string (confirmed for real -- it only truncates
            // time on the PHP/Carbon side, not in the database), which would silently
            // break RollupUsageSnapshots' own updateOrCreate lookup, comparing against
            // a plain "Y-m-d" string for idempotency.
            'period' => 'date:Y-m-d',
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
     * The real resource this rollup summarizes: a MailAccount, a TenantDatabase, or a WebDomain.
     *
     * @return MorphTo<Model, $this>
     */
    public function snapshotable(): MorphTo
    {
        return $this->morphTo();
    }
}
