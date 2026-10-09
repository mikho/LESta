<?php

namespace App\Actions\Provisioning;

use App\Actions\AccountBackups\CopyAccountBackupOffNode;
use App\Actions\AccountBackups\DeleteAccountBackup;
use App\Actions\AccountBackups\RestoreAccountBackup;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\AccountBackup;
use App\Models\AccountBackupDownload;
use App\Models\ProvisioningOperation;
use Illuminate\Support\Facades\Storage;

/**
 * The completion side of a self-service backup: writes what the node reported (size, checksum,
 * parts, a per-part report, or the reason it failed) onto the AccountBackup, starts the restore
 * a safety snapshot was taken for, records a restore's outcome, and prunes the oldest backups
 * beyond the per-kind limits in AccountBackup::KEEP_BY_KIND.
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
            ProvisioningVerb::Observe => $this->recordDownload($backup, $operation),
            ProvisioningVerb::Update => $this->recordCopy($backup, $operation),
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
            app(CopyAccountBackupOffNode::class)->handleAutomatic($backup);
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

    /**
     * The node's report on streaming a download: the upload endpoint marks the copy ready when the
     * final chunk arrives, so only a failure (or a report of success without that) matters here.
     */
    private function recordDownload(AccountBackup $backup, ProvisioningOperation $operation): void
    {
        $succeeded = in_array($operation->status, [ProvisioningStatus::Applied, ProvisioningStatus::AlreadyApplied], true);

        $backup->downloads()->where('status', 'pending')->each(function (AccountBackupDownload $download) use ($operation, $succeeded): void {
            if ($download->path !== null) {
                Storage::disk('local')->delete($download->path);
            }

            $download->forceFill([
                'status' => 'failed',
                'path' => null,
                'size_bytes' => 0,
                'error_message' => $succeeded ? __('The download was not completed.') : ($operation->errors[0]['message'] ?? __('The download could not be prepared.')),
            ])->save();
        });
    }

    /**
     * The node's report on copying the backup to the account's storage.
     */
    private function recordCopy(AccountBackup $backup, ProvisioningOperation $operation): void
    {
        if (in_array($operation->status, [ProvisioningStatus::Applied, ProvisioningStatus::AlreadyApplied], true)) {
            $backup->forceFill(['remote_status' => 'copied', 'remote_error' => null, 'remote_at' => $operation->completed_at ?? now()])->save();

            return;
        }

        $backup->forceFill([
            'remote_status' => 'failed',
            'remote_error' => $operation->errors[0]['message'] ?? __('The copy to your storage failed.'),
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
     * Keeps the newest few completed backups of each kind (AccountBackup::KEEP_BY_KIND) for the
     * account on the node, so a run of scheduled backups never pushes out the manual ones; anything
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

        foreach (AccountBackup::KEEP_BY_KIND as $kind => $keep) {
            AccountBackup::query()
                ->where('account_id', $latest->account_id)
                ->where('node_id', $latest->node_id)
                ->where('kind', $kind)
                ->whereIn('status', [ProvisioningStatus::Applied->value, ProvisioningStatus::AlreadyApplied->value])
                ->orderByDesc('completed_at')
                ->orderByDesc('id')
                ->offset($keep)
                ->limit(1000)
                ->get()
                ->reject(fn (AccountBackup $old): bool => in_array($old->id, $inUse, true) || $old->id === $latest->id)
                ->each(fn (AccountBackup $old) => app(DeleteAccountBackup::class)->handleSystemInitiated($old));
        }
    }
}
