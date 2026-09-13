<?php

namespace App\Console\Commands;

use App\Actions\Metrics\CollectUsageMetrics as CollectUsageMetricsAction;
use App\Models\Node;
use Illuminate\Console\Command;

class CollectUsageMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'metrics:collect';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch a real metrics.usage.v1 observe operation for every non-suspended node.';

    public function handle(CollectUsageMetricsAction $collectUsageMetrics): int
    {
        Node::query()
            ->whereNull('suspended_at')
            ->each(function (Node $node) use ($collectUsageMetrics): void {
                $collectUsageMetrics->handle($node);
            });

        return self::SUCCESS;
    }
}
