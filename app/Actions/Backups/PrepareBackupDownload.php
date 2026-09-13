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
 * Dispatches a real Observe operation asking the owning node to read its own sealed artifact
 * bytes back (see backup.encrypted-artifacts.v1's own new applyObserve); PreparesBackupDownload,
 * a CompletesProvisioningOperation hook, does the actual decrypt-and-store once that operation's
 * result comes back, since this is only ever asynchronous (the real node may not be reachable
 * again until its next heartbeat).
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

        DB::transaction(function () use ($actor, $backup): void {
            $capability = app(ResolvesBackupCapableNode::class)->resolveFor($backup->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $backup->getMorphClass(),
                'auditable_id' => $backup->getKey(),
                'action' => 'backup.download_prepared',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $backup,
                $capability,
                ProvisioningVerb::Observe,
                $backup->toProvisioningPayload(),
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
