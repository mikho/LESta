<?php

namespace App\Jobs;

use App\Actions\Provisioning\TriggersAcmeCertificateIssuance;
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

    // Deliberately still a single-dependency signature (Provisioner only):
    // existing tests (e.g. DuplicateDeliveryTest) call handle() directly
    // with one manually-resolved argument rather than letting the queue
    // worker's own container resolution supply every parameter, so adding a
    // second method-injected parameter here would break that established
    // calling convention. TriggersAcmeCertificateIssuance is resolved via
    // the container instead, exactly like every provisioner-agnostic Action
    // this job already calls (RecordsProvisioningOperation, etc.).
    public function handle(Provisioner $provisioner): void
    {
        $triggersAcmeCertificateIssuance = app(TriggersAcmeCertificateIssuance::class);

        DB::transaction(function () use ($provisioner, $triggersAcmeCertificateIssuance): void {
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

            // The one hook point ACME issuance needs: never couples this
            // provisionable-agnostic job to ACME-specific knowledge itself,
            // see TriggersAcmeCertificateIssuance's own doc comment.
            $triggersAcmeCertificateIssuance->handle($operation);
        });
    }
}
