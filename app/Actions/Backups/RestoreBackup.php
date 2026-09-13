<?php

namespace App\Actions\Backups;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesBackupCapableNode;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Dispatches a real Restore operation asking the owning node to put back the two things no other
 * capability's own generation history can regenerate: mail's own real maildir content and each
 * database capability's own real mysqldump output, decrypted and applied entirely on that node
 * (the archive never leaves its own local disk -- see backup.encrypted-artifacts.v1's own
 * applyRestore, which needs only the plaintext encryption key this dispatch sends down, not the
 * artifact bytes themselves, unlike the download flow's own reverse direction). Once this
 * operation completes, CascadesRestoreIntoResync (a CompletesProvisioningOperation hook) brings
 * the rest of the node -- every web domain, DNS zone, cron job, and mail domain's own structural
 * config -- back in sync with current desired state via App\Actions\Nodes\ResyncNode.
 */
class RestoreBackup
{
    public function handle(User $actor, Backup $backup): void
    {
        Gate::forUser($actor)->authorize('restore', $backup);

        if (! in_array($backup->status, [ProvisioningStatus::Applied, ProvisioningStatus::AlreadyApplied], true)) {
            throw ValidationException::withMessages([
                'backup' => 'Only a completed backup can be restored.',
            ]);
        }

        if ($this->isAlreadyRestoring($backup)) {
            throw ValidationException::withMessages([
                'backup' => 'This backup is already being restored.',
            ]);
        }

        DB::transaction(function () use ($actor, $backup): void {
            $capability = app(ResolvesBackupCapableNode::class)->resolveFor($backup->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $backup->getMorphClass(),
                'auditable_id' => $backup->getKey(),
                'action' => 'backup.restore_started',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $backup,
                $capability,
                ProvisioningVerb::Restore,
                $backup->toRestorePayload(),
                $correlationId,
                $backup->desired_state_version,
            );
        });
    }

    private function isAlreadyRestoring(Backup $backup): bool
    {
        $latest = $backup->latestProvisioningOperation;

        return $latest !== null
            && $latest->operation === ProvisioningVerb::Restore
            && in_array($latest->status, [ProvisioningStatus::Pending, ProvisioningStatus::Dispatched], true);
    }
}
