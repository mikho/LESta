<?php

namespace App\Console\Commands;

use App\Models\UsageSnapshot;
use App\Models\UsageSnapshotRollup;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Aggregates every UsageSnapshot in a calendar month strictly before the current one into one
 * UsageSnapshotRollup row per resource, so that history survives PruneUsageSnapshots' own 90-day
 * deletion instead of being lost outright. Run daily, right before metrics:prune (see
 * routes/console.php): a month typically gets its rollup computed on the first few days of the
 * following month and is then a no-op every day after, since recomputing an already-finalized
 * month from the same underlying raw rows always yields the same result -- but it stays correct
 * even after a missed run, a backfill, or a period run out of order, since it always aggregates
 * from whatever raw rows are still present rather than tracking "already done" state anywhere.
 * The current, still-in-progress month is deliberately never rolled up: its data is incomplete
 * until the month ends.
 */
class RollupUsageSnapshots extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'metrics:rollup';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Aggregate completed months of usage snapshots into monthly rollups.';

    public function handle(): int
    {
        $currentMonthStart = now()->startOfMonth();

        $groups = UsageSnapshot::query()
            ->where('collected_at', '<', $currentMonthStart)
            ->orderBy('collected_at')
            ->get()
            ->groupBy(fn (UsageSnapshot $snapshot): string => implode('|', [
                $snapshot->account_id,
                $snapshot->node_id,
                $snapshot->snapshotable_type,
                $snapshot->snapshotable_id,
                $snapshot->collected_at->format('Y-m'),
            ]));

        foreach ($groups as $group) {
            $this->upsertRollup($group);
        }

        $this->info("Rolled up {$groups->count()} resource-month(s) of usage snapshots.");

        return self::SUCCESS;
    }

    /**
     * @param  Collection<int, UsageSnapshot>  $group  every snapshot for one resource in one
     *                                                 calendar month, ordered oldest first
     */
    private function upsertRollup(Collection $group): void
    {
        $first = $group->first();
        $last = $group->last();

        UsageSnapshotRollup::query()->updateOrCreate(
            [
                'snapshotable_type' => $first->snapshotable_type,
                'snapshotable_id' => $first->snapshotable_id,
                'period' => $first->collected_at->copy()->startOfMonth()->toDateString(),
            ],
            [
                'account_id' => $first->account_id,
                'node_id' => $first->node_id,
                'disk_bytes_last' => $last->disk_bytes,
                'request_count_sum' => $this->sumOrNull($group, 'request_count'),
                'bytes_sent_sum' => $this->sumOrNull($group, 'bytes_sent'),
            ],
        );
    }

    /**
     * Sums a nullable numeric column across the group, preserving null when every value in the
     * group is null (a resource type that never populates this column, e.g. a mail account's own
     * request_count) rather than collapsing it to a misleading 0.
     *
     * @param  Collection<int, UsageSnapshot>  $group
     */
    private function sumOrNull(Collection $group, string $column): ?int
    {
        $values = $group->pluck($column)->filter(fn (?int $value): bool => $value !== null);

        return $values->isEmpty() ? null : $values->sum();
    }
}
