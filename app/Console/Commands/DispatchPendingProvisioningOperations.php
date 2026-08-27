<?php

namespace App\Console\Commands;

use App\Enums\ProvisioningStatus;
use App\Jobs\DispatchProvisioningOperation;
use App\Models\ProvisioningOperation;
use Illuminate\Console\Command;

class DispatchPendingProvisioningOperations extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'provisioning:dispatch-pending';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Re-dispatch provisioning operations still pending past the staleness window.';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $staleBefore = now()->subMinutes((int) config('provisioning.stale_after_minutes', 5));

        ProvisioningOperation::query()
            ->where('status', ProvisioningStatus::Pending)
            ->where('issued_at', '<=', $staleBefore)
            ->each(function (ProvisioningOperation $operation): void {
                DispatchProvisioningOperation::dispatch($operation->id);
            });

        return self::SUCCESS;
    }
}
