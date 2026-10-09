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
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Restores chosen parts of a backup into the account's current resources on its node. A restore
 * first takes a safety snapshot of what is about to be overwritten ("Before restoring ..."), and
 * is dispatched when that snapshot completes (see RecordsAccountBackupResult); if the snapshot
 * fails, nothing is restored. When there is nothing present to snapshot (the site was removed),
 * the restore starts straight away.
 */
class RestoreAccountBackup
{
    /**
     * @param  list<string>  $parts
     */
    public function handle(User $actor, AccountBackup $backup, array $parts): void
    {
        Gate::forUser($actor)->authorize('restore', $backup);

        $parts = array_values(array_intersect(AccountBackup::PARTS, $parts));

        if (! $backup->isComplete() || $backup->artifact_path === null) {
            throw ValidationException::withMessages(['backup' => __('Only a completed backup can be restored.')]);
        }

        if ($parts === [] || array_diff($parts, $backup->parts ?? []) !== []) {
            throw ValidationException::withMessages(['parts' => __('Choose parts that this backup contains.')]);
        }

        $create = app(CreateAccountBackup::class);

        if ($create->isBusy($backup->account, $backup->node)) {
            throw ValidationException::withMessages(['backup' => __('A backup or restore is already running for this account on :node.', ['node' => $backup->node->name])]);
        }

        DB::transaction(function () use ($actor, $backup, $parts, $create): void {
            $backup->forceFill(['last_restore_status' => 'running', 'last_restore_at' => now(), 'last_restore_report' => null, 'last_restore_error' => null])->save();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $backup->getMorphClass(),
                'auditable_id' => $backup->getKey(),
                'action' => 'account_backup.restore_started',
                'correlation_id' => (string) Str::uuid(),
                'metadata' => ['parts' => $parts],
            ]);

            $resolver = app(ResolvesAccountBackupScope::class);
            $present = array_values(array_intersect($parts, $resolver->availableParts($resolver->for($backup->account, $backup->node))));

            if ($present === []) {
                $this->dispatch($backup, $parts);

                return;
            }

            $create->forRestore($actor, $backup, $present, $parts);
        });
    }

    /**
     * Sends the restore to the node: called straight away, or when the safety snapshot completes.
     *
     * @param  list<string>  $parts
     */
    public function dispatch(AccountBackup $backup, array $parts): void
    {
        $capability = app(ResolvesBackupCapableNode::class)->resolveFor($backup->node);
        $scope = app(ResolvesAccountBackupScope::class)->for($backup->account, $backup->node);

        app(RecordsProvisioningOperation::class)->record(
            $backup,
            $capability,
            ProvisioningVerb::Restore,
            $backup->toRestorePayload($scope, $parts),
            (string) Str::uuid(),
            $backup->desired_state_version,
        );
    }
}
