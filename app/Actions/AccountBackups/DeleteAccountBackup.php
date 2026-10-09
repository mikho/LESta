<?php

namespace App\Actions\AccountBackups;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesBackupCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AccountBackup;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Deletes a backup: the node removes the archive, and the row goes. A backup that is being
 * restored cannot be deleted while it runs.
 */
class DeleteAccountBackup
{
    public function handle(User $actor, AccountBackup $backup): void
    {
        Gate::forUser($actor)->authorize('delete', $backup);

        if ($backup->isRestoring()) {
            throw ValidationException::withMessages(['backup' => __('This backup is being restored. Try again when the restore has finished.')]);
        }

        DB::transaction(fn () => $this->purge($backup, $actor));
    }

    /**
     * The retention-driven counterpart: the oldest backups beyond AccountBackup::KEEP.
     */
    public function handleSystemInitiated(AccountBackup $backup): void
    {
        DB::transaction(fn () => $this->purge($backup, null));
    }

    private function purge(AccountBackup $backup, ?User $actor): void
    {
        $correlationId = (string) Str::uuid();

        AuditEvent::create([
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'auditable_type' => $backup->getMorphClass(),
            'auditable_id' => $backup->getKey(),
            'action' => 'account_backup.deleted',
            'correlation_id' => $correlationId,
        ]);

        if ($backup->artifact_path !== null) {
            app(RecordsProvisioningOperation::class)->record(
                $backup,
                app(ResolvesBackupCapableNode::class)->resolveFor($backup->node),
                ProvisioningVerb::Delete,
                $backup->toDeletePayload(),
                $correlationId,
                $backup->desired_state_version,
            );
        }

        // The prepared downloads go with the backup; their rows are removed by the cascade.
        $backup->downloads()->whereNotNull('path')->pluck('path')->each(fn (string $path) => Storage::disk('local')->delete($path));

        $backup->delete();
    }
}
