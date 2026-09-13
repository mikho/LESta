<?php

namespace App\Console\Commands;

use App\Models\UsageSnapshot;
use Illuminate\Console\Command;

/**
 * Deletes UsageSnapshot rows past the retention window, per the Statistics design's own bounded-
 * collection decision (90 days of raw snapshots): a real, disclosed bound, not an unbounded
 * table left to grow forever. There is no aggregation/rollup into a coarser-grained history yet
 * (e.g. daily -> monthly summaries) -- a real, deliberate v1 boundary, not an oversight: nothing
 * in this pass reads UsageSnapshot for long-range trend reporting yet, only the raw daily rows a
 * future dashboard would need.
 */
class PruneUsageSnapshots extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'metrics:prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete usage snapshots past the 90-day retention window.';

    public function handle(): int
    {
        $deleted = UsageSnapshot::query()
            ->where('collected_at', '<', now()->subDays(90))
            ->delete();

        $this->info("Deleted {$deleted} usage snapshot(s) older than 90 days.");

        return self::SUCCESS;
    }
}
