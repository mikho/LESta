<?php

namespace App\Jobs;

use App\Contracts\Provisioner;
use App\Enums\ProvisioningStatus;
use App\Models\ProvisioningOperation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;

class DispatchProvisioningOperation implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $provisioningOperationId) {}

    public function handle(Provisioner $provisioner): void
    {
        DB::transaction(function () use ($provisioner): void {
            $operation = ProvisioningOperation::query()
                ->whereKey($this->provisioningOperationId)
                ->lockForUpdate()
                ->first();

            if ($operation === null || $operation->status !== ProvisioningStatus::Pending) {
                // Duplicate delivery guard: a redelivered queue message, or a row already
                // handled by a previous invocation, is a no-op.
                return;
            }

            $operation->forceFill([
                'status' => ProvisioningStatus::Dispatched,
                'dispatched_at' => now(),
                'attempts' => $operation->attempts + 1,
                'deadline' => $operation->deadline
                    ?? now()->addMinutes((int) config('provisioning.dispatch_deadline_minutes', 5)),
            ])->save();

            $result = $provisioner->apply($operation);

            $operation->forceFill([
                'status' => $result->status,
                'observed_state_version' => $result->observedStateVersion,
                'observed_state_digest' => $result->observedStateDigest,
                'generation_id' => $result->generationId,
                'errors' => $result->errors,
                'completed_at' => $result->completedAt,
            ])->save();
        });
    }
}
