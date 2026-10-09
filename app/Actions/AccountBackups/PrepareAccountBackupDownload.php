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

        return DB::transaction(function () use ($actor, $backup): AccountBackupDownload {
            $token = bin2hex(random_bytes(32));

            $download = $backup->downloads()->create([
                'token_hash' => AccountBackupDownload::hashToken($token),
                'status' => 'pending',
                'size_bytes' => 0,
                'expires_at' => now()->addMinutes(AccountBackupDownload::PREPARE_MINUTES),
            ]);

            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $backup->getMorphClass(),
                'auditable_id' => $backup->getKey(),
                'action' => 'account_backup.download_prepared',
                'correlation_id' => $correlationId,
            ]);

            $scope = app(ResolvesAccountBackupScope::class)->for($backup->account, $backup->node);

            app(RecordsProvisioningOperation::class)->record(
                $backup,
                app(ResolvesBackupCapableNode::class)->resolveFor($backup->node),
                ProvisioningVerb::Observe,
                $backup->toDownloadPayload($scope, route('agent.account-backup-uploads', ['token' => $token])),
                $correlationId,
                $backup->desired_state_version,
            );

            return $download;
        });
    }
}
