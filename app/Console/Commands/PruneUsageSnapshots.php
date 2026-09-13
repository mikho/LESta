<?php

namespace App\Console\Commands;

use App\Models\UsageSnapshot;
use Illuminate\Console\Command;

/**
 * Deletes UsageSnapshot rows past the retention window, per the Statistics design's own bounded-
 * collection decision (90 days of raw snapshots): a real, disclosed bound, not an unbounded
 * table left to grow forever. Long-range history survives this deletion via
 * App\Console\Commands\RollupUsageSnapshots, which aggregates each completed month into a
 * UsageSnapshotRollup row before its raw snapshots ever reach this command (see routes/console.php
 * for the schedule ordering that guarantees this).
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
