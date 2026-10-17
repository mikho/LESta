<?php

namespace App\Actions\Webhooks;

use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Http\Resources\Api\V1\AccountBackupResource;
use App\Http\Resources\Api\V1\NodeBackupResource;
use App\Http\Resources\Api\V1\OperationResource;
use App\Models\AccountBackup;
use App\Models\AuditEvent;
use App\Models\Backup;
use App\Models\ProvisioningOperation;

/**
 * After a node's result is recorded: the events it finished. operation.finished once every step
 * of the change is done; backup.finished when an account or whole-node backup is ready or failed;
 * restore.finished when a restore is done or failed (also when its safety backup failed, which
 * fails the restore). The payloads are the API's own representations.
 */
class QueuesFinishedWebhookEvents
{
    public function __construct(private QueuesWebhookEvent $queue) {}

    public function handle(ProvisioningOperation $operation): void
    {
        $this->operationFinished($operation);

        $subject = $operation->provisionable;

        if ($subject instanceof AccountBackup) {
            $this->accountBackupFinished($subject->refresh(), $operation);
        } elseif ($subject instanceof Backup && $operation->operation === ProvisioningVerb::Create && $this->isFinal($subject->status)) {
            $this->queue->handle('backup.finished', 'backup.finished:'.$subject->uuid, null, [
                'node_backup' => (new NodeBackupResource($subject->load('node')))->resolve(),
            ]);
        }
    }

    private function operationFinished(ProvisioningOperation $operation): void
    {
        $steps = ProvisioningOperation::query()->where('correlation_id', $operation->correlation_id)->orderBy('id')->get();

        if ($steps->contains(fn (ProvisioningOperation $step): bool => ! $this->isFinal($step->status))) {
            return;
        }

        $event = AuditEvent::query()->where('correlation_id', $operation->correlation_id)->orderBy('id')->first();

        if ($event === null) {
            return;
        }

        $this->queue->handle('operation.finished', 'operation.finished:'.$operation->correlation_id, $event->account_id, [
            'operation' => (new OperationResource($event, $steps))->resolve(),
        ]);
    }

    private function accountBackupFinished(AccountBackup $backup, ProvisioningOperation $operation): void
    {
        if ($operation->operation === ProvisioningVerb::Create && $backup->state() !== 'running') {
            $this->queue->handle('backup.finished', 'backup.finished:'.$backup->uuid, $backup->account_id, [
                'backup' => (new AccountBackupResource($backup->load('node')))->resolve(),
            ]);

            // A failed safety backup fails the restore that was waiting for it.
            if ($backup->state() === 'failed' && $backup->restore_after_id !== null) {
                $target = AccountBackup::query()->find($backup->restore_after_id);

                if ($target !== null) {
                    $this->restoreFinished($target);
                }
            }
        }

        if ($operation->operation === ProvisioningVerb::Restore) {
            $this->restoreFinished($backup);
        }
    }

    private function restoreFinished(AccountBackup $backup): void
    {
        if (! in_array($backup->last_restore_status, ['applied', 'failed'], true)) {
            return;
        }

        $this->queue->handle('restore.finished', 'restore.finished:'.$backup->uuid.':'.$backup->last_restore_at?->getTimestamp(), $backup->account_id, [
            'backup' => (new AccountBackupResource($backup->load('node')))->resolve(),
        ]);
    }

    private function isFinal(ProvisioningStatus $status): bool
    {
        return ! in_array($status, [ProvisioningStatus::Pending, ProvisioningStatus::Dispatched], true);
    }
}
