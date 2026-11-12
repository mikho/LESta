<?php

namespace App\Actions\AccountBackups;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesBackupCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AccountBackup;
use App\Models\AccountBackupDownload;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Asks the node to stream a decrypted copy of an account backup to the control plane so the owner
 * can download it. The node is given a one-time upload address (a random token, only its hash is
 * kept here) and sends the backup as a plain tar.gz in chunks; the copy is kept for an hour.
 */
class PrepareAccountBackupDownload
{
    public function handle(User $actor, AccountBackup $backup): AccountBackupDownload
    {
        Gate::forUser($actor)->authorize('download', $backup);

        if (! $backup->isComplete() || $backup->artifact_path === null) {
            throw ValidationException::withMessages(['backup' => __('Only a completed backup can be downloaded.')]);
        }

        if (($backup->size_bytes ?? 0) > AccountBackupDownload::MAX_BYTES) {
            throw ValidationException::withMessages(['backup' => __('This backup is too large to download here (more than 4 GB).')]);
        }

        $existing = $backup->downloads()->whereIn('status', ['pending', 'ready'])->where('expires_at', '>', now())->latest('id')->first();

        if ($existing !== null) {
            return $existing;
        }

        $otherInProgress = AccountBackupDownload::query()
            ->whereIn('status', ['pending', 'ready'])
            ->where('expires_at', '>', now())
            ->whereHas('backup', fn ($query) => $query->where('account_id', $backup->account_id)->where('id', '!=', $backup->id))
            ->exists();

        if ($otherInProgress) {
            throw ValidationException::withMessages(['backup' => __('Another download is being prepared or is waiting to be fetched. Download or wait for it to expire first.')]);
        }

        if (! app(ChecksStagingSpace::class)->hasRoomFor((int) ($backup->size_bytes ?? 0) * 2)) {
            throw ValidationException::withMessages(['backup' => __('The server has no room to prepare a download right now. Try again later.')]);
        }

        return DB::transaction(function () use ($actor, $backup): AccountBackupDownload {
            $token = bin2hex(random_bytes(32));

            $download = $backup->downloads()->create([
                'token_hash' => AccountBackupDownload::hashToken($token),
                'status' => 'pending',
                'size_bytes' => 0,
                'expires_at' => now()->addMinutes(AccountBackupDownload::PREPARE_MINUTES),
            ]);

            $correlationId = (string) Str::uuid();

            AuditEvent::record($backup, 'account_backup.download_prepared', $actor, $correlationId);

            $scope = app(ResolvesAccountBackupScope::class)->for($backup->account, $backup->node);

            app(RecordsProvisioningOperation::class)->record(
                $backup,
                app(ResolvesBackupCapableNode::class)->resolveFor($backup->node),
                ProvisioningVerb::Observe,
                $backup->toDownloadPayload($scope, route('agent.account-backup-uploads'), $token),
                $correlationId,
                $backup->desired_state_version,
            );

            return $download;
        });
    }
}
