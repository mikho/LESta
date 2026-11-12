<?php

namespace App\Actions\Backups;

use App\Actions\AccountBackups\ChecksStagingSpace;
use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesBackupCapableNode;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Dispatches a real Observe operation asking the owning node to decrypt its own artifact and stream
 * it here, in chunks, to a one-time address (see AgentBackupUploadController). The decrypted copy
 * only becomes downloadable when the node has sent the whole of it. An agent from before streaming
 * ignores the address and returns the sealed artifact in the result instead; PreparesBackupDownload,
 * a CompletesProvisioningOperation hook, decrypts that, since this is only ever asynchronous (the
 * real node may not be reachable again until its next heartbeat).
 */
class PrepareBackupDownload
{
    public function handle(User $actor, Backup $backup): void
    {
        Gate::forUser($actor)->authorize('download', $backup);

        if (! in_array($backup->status, [ProvisioningStatus::Applied, ProvisioningStatus::AlreadyApplied], true)) {
            throw ValidationException::withMessages([
                'backup' => 'Only a completed backup can be prepared for download.',
            ]);
        }

        if ($this->isAlreadyPreparing($backup)) {
            throw ValidationException::withMessages([
                'backup' => 'This backup is already being prepared for download.',
            ]);
        }

        if (($backup->size_bytes ?? 0) > Backup::MAX_DOWNLOAD_BYTES) {
            throw ValidationException::withMessages([
                'backup' => 'This backup is too large to download here (more than 32 GB).',
            ]);
        }

        if (! app(ChecksStagingSpace::class)->hasRoomFor((int) ($backup->size_bytes ?? 0) * 2)) {
            throw ValidationException::withMessages([
                'backup' => 'The server has no room to prepare a download right now. Try again later.',
            ]);
        }

        DB::transaction(function () use ($actor, $backup): void {
            $capability = app(ResolvesBackupCapableNode::class)->resolveFor($backup->node);
            $correlationId = (string) Str::uuid();

            // A copy left from an earlier request goes; the node sends a fresh one to a new one-time address.
            if ($backup->download_path !== null) {
                Storage::disk('local')->delete($backup->download_path);
            }

            $token = bin2hex(random_bytes(32));

            $backup->forceFill([
                'download_path' => null,
                'download_ready_at' => null,
                'download_expires_at' => now()->addMinutes(Backup::UPLOAD_MINUTES),
                'download_token_hash' => Backup::hashDownloadToken($token),
            ])->save();

            AuditEvent::record($backup, 'backup.download_prepared', $actor, $correlationId);

            app(RecordsProvisioningOperation::class)->record(
                $backup,
                $capability,
                ProvisioningVerb::Observe,
                $backup->toDownloadPayload(route('agent.backup-uploads'), $token),
                $correlationId,
                $backup->desired_state_version,
            );
        });
    }

    private function isAlreadyPreparing(Backup $backup): bool
    {
        $latest = $backup->latestProvisioningOperation;

        return $latest !== null
            && $latest->operation === ProvisioningVerb::Observe
            && in_array($latest->status, [ProvisioningStatus::Pending, ProvisioningStatus::Dispatched], true);
    }
}
