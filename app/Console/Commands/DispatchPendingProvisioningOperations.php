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
        $this->redispatchStalePendingOperations();
        $this->failOperationsPastTheirDeadline();

        return self::SUCCESS;
    }

    /**
     * Re-dispatch operations still Pending past the staleness window: the job that should
     * have dispatched them never ran, or failed silently.
     */
    private function redispatchStalePendingOperations(): void
    {
        $staleBefore = now()->subMinutes((int) config('provisioning.stale_after_minutes', 5));

        ProvisioningOperation::query()
            ->where('status', ProvisioningStatus::Pending)
            ->where('issued_at', '<=', $staleBefore)
            ->each(function (ProvisioningOperation $operation): void {
                DispatchProvisioningOperation::dispatch($operation->id);
            });
    }

    /**
     * Fail operations still Dispatched past their own deadline: the owning node's agent
     * daemon never reported a result in time, so this is the backstop that reconciles a
     * stuck operation to a terminal status rather than leaving it Dispatched forever.
     */
    private function failOperationsPastTheirDeadline(): void
    {
        ProvisioningOperation::query()
            ->where('status', ProvisioningStatus::Dispatched)
            ->where('deadline', '<', now())
            ->each(function (ProvisioningOperation $operation): void {
                $operation->forceFill([
                    'status' => ProvisioningStatus::Failed,
                    'errors' => [['code' => 'deadline_exceeded', 'message' => 'Operation exceeded its dispatch deadline without a result from the node.']],
                    'completed_at' => now(),
                ])->save();
            });
    }
}
