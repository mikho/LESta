<?php

namespace App\Actions\Backups;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesBackupCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\Backup;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class DeleteBackup
{
    public function handle(User $actor, Backup $backup): void
    {
        Gate::forUser($actor)->authorize('delete', $backup);

        DB::transaction(function () use ($actor, $backup): void {
            $this->dispatchDeleteAndPurge($backup, $actor);
        });
    }

    /**
     * The retention-driven counterpart to handle(): RecordsBackupArtifact prunes the oldest
     * backups past the per-node retention count once a new one completes, which is not a user
     * action and has no actor to authorize against or attribute an AuditEvent to (actor_type/
     * actor_id are nullable for exactly this: a system-initiated audit trail entry is still a
     * real entry, just an unattributed one).
     */
    public function handleSystemInitiated(Backup $backup): void
    {
        DB::transaction(function () use ($backup): void {
            $this->dispatchDeleteAndPurge($backup, null);
        });
    }

    private function dispatchDeleteAndPurge(Backup $backup, ?User $actor): void
    {
        $capability = app(ResolvesBackupCapableNode::class)->resolveFor($backup->node);
        $correlationId = (string) Str::uuid();

        AuditEvent::create([
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'auditable_type' => $backup->getMorphClass(),
            'auditable_id' => $backup->getKey(),
            'action' => 'backup.deleted',
            'correlation_id' => $correlationId,
        ]);

        app(RecordsProvisioningOperation::class)->record(
            $backup,
            $capability,
            ProvisioningVerb::Delete,
            $backup->toProvisioningPayload(),
            $correlationId,
            $backup->desired_state_version,
        );

        $backup->delete();
    }
}
