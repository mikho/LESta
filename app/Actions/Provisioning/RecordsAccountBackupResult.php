<?php

namespace App\Actions\Provisioning;

use App\Actions\AccountBackups\DeleteAccountBackup;
use App\Actions\AccountBackups\RestoreAccountBackup;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\AccountBackup;
use App\Models\ProvisioningOperation;

/**
 * The completion side of a self-service backup: writes what the node reported (size, checksum,
 * parts, a per-part report, or the reason it failed) onto the AccountBackup, starts the restore
 * a safety snapshot was taken for, records a restore's outcome, and prunes the oldest backups
 * beyond AccountBackup::KEEP.
 */
class RecordsAccountBackupResult
{
    public function handle(ProvisioningOperation $operation): void
    {
        $backup = $operation->provisionable;

        if (! $backup instanceof AccountBackup) {
            return;
        }

        match ($operation->operation) {
            ProvisioningVerb::Create => $this->recordCreate($backup, $operation),
            ProvisioningVerb::Restore => $this->recordRestore($backup, $operation),
            default => null,
        };
    }

    private function recordCreate(AccountBackup $backup, ProvisioningOperation $operation): void
    {
        if (in_array($operation->status, [ProvisioningStatus::Applied, ProvisioningStatus::AlreadyApplied], true)) {
            $data = $operation->data ?? [];

            $backup->forceFill([
                'status' => $operation->status,
                'parts' => $data['parts'] ?? null,
                'size_bytes' => $data['size_bytes'] ?? null,
                'checksum' => $data['checksum'] ?? null,
                'artifact_path' => $data['artifact_path'] ?? null,
                'report' => $data['report'] ?? null,
                'completed_at' => $operation->completed_at,
            ])->save();

            $this->startPendingRestore($backup);
            $this->pruneOldest($backup);

            return;
        }

        if (in_array($operation->status, [ProvisioningStatus::Failed, ProvisioningStatus::Rejected, ProvisioningStatus::Degraded], true)) {
            $message = $operation->errors[0]['message'] ?? __('The backup could not be created.');

            $backup->forceFill([
                'status' => ProvisioningStatus::Failed,
                'error_message' => $message,
                'completed_at' => $operation->completed_at,
            ])->save();

            $this->cancelPendingRestore($backup, $message);
        }
    }

    private function startPendingRestore(AccountBackup $safety): void
    {
        $target = $safety->restore_after_id !== null ? AccountBackup::query()->find($safety->restore_after_id) : null;

        if ($target === null || ! $target->isRestoring()) {
            return;
        }

        app(RestoreAccountBackup::class)->dispatch($target, $safety->restore_parts ?? []);
    }

    private function cancelPendingRestore(AccountBackup $safety, string $reason): void
    {
        $target = $safety->restore_after_id !== null ? AccountBackup::query()->find($safety->restore_after_id) : null;

        if ($target === null || ! $target->isRestoring()) {
            return;
        }

        $target->forceFill([
            'last_restore_status' => 'failed',
            'last_restore_error' => __('Nothing was restored: the safety backup taken first failed (:reason).', ['reason' => $reason]),
        ])->save();
    }

    private function recordRestore(AccountBackup $backup, ProvisioningOperation $operation): void
    {
        if (in_array($operation->status, [ProvisioningStatus::Applied, ProvisioningStatus::AlreadyApplied], true)) {
            $backup->forceFill([
                'last_restore_status' => 'applied',
                'last_restore_report' => $operation->data,
                'last_restore_error' => null,
            ])->save();

            return;
        }

        $backup->forceFill([
            'last_restore_status' => 'failed',
            'last_restore_error' => $operation->errors[0]['message'] ?? __('The restore failed.'),
        ])->save();
    }

    /**
     * Keeps the newest AccountBackup::KEEP completed backups of the account on the node; anything
     * older that is not in use goes.
     */
    private function pruneOldest(AccountBackup $latest): void
    {
        $inUse = AccountBackup::query()
            ->where('account_id', $latest->account_id)
            ->where('node_id', $latest->node_id)
            ->where('last_restore_status', 'running')
            ->pluck('id')
            ->all();

        AccountBackup::query()
            ->where('account_id', $latest->account_id)
            ->where('node_id', $latest->node_id)
            ->whereIn('status', [ProvisioningStatus::Applied->value, ProvisioningStatus::AlreadyApplied->value])
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->offset(AccountBackup::KEEP)
            ->limit(1000)
            ->get()
            ->reject(fn (AccountBackup $old): bool => in_array($old->id, $inUse, true) || $old->id === $latest->id)
            ->each(fn (AccountBackup $old) => app(DeleteAccountBackup::class)->handleSystemInitiated($old));
    }
}
