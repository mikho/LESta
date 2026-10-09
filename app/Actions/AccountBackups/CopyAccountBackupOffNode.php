<?php

namespace App\Actions\AccountBackups;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesBackupCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AccountBackup;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Copies a finished backup, decrypted as a plain tar.gz, to the account's own S3-compatible storage.
 * The node checks that the whole backup is intact before anything is uploaded. Started
 * automatically after a manual or scheduled backup completes (see RecordsAccountBackupResult), and
 * by the owner for a backup that has not been copied or whose copy failed. LESta never deletes a
 * copy: retention in the bucket is the owner's.
 */
class CopyAccountBackupOffNode
{
    /**
     * The owner's request (a retry, or a copy of an older backup).
     */
    public function handle(User $actor, AccountBackup $backup): void
    {
        Gate::forUser($actor)->authorize('download', $backup);

        $this->dispatch($backup, $actor, true);
    }

    /**
     * The automatic copy after a backup completes: skipped silently when there is no destination.
     */
    public function handleAutomatic(AccountBackup $backup): void
    {
        if (in_array($backup->kind, ['before_restore', 'imported'], true) || $backup->account->backupDestination?->enabled !== true) {
            return;
        }

        try {
            $this->dispatch($backup, null, false);
        } catch (ValidationException) {
            // Nothing to do: the backup keeps its state and the owner can retry.
        }
    }

    private function dispatch(AccountBackup $backup, ?User $actor, bool $strict): void
    {
        $destination = $backup->account->backupDestination;

        if ($destination === null || ! $destination->enabled) {
            throw ValidationException::withMessages(['backup' => __('Set up your own storage first.')]);
        }

        if (! $backup->isComplete() || $backup->artifact_path === null) {
            throw ValidationException::withMessages(['backup' => __('Only a completed backup can be copied.')]);
        }

        if ($backup->remote_status === 'copying') {
            if ($strict) {
                throw ValidationException::withMessages(['backup' => __('This backup is already being copied.')]);
            }

            return;
        }

        $key = $this->objectKey($backup, $destination->prefix);

        $backup->forceFill(['remote_status' => 'copying', 'remote_key' => $key, 'remote_error' => null])->save();

        $correlationId = (string) Str::uuid();

        AuditEvent::create([
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'auditable_type' => $backup->getMorphClass(),
            'auditable_id' => $backup->getKey(),
            'action' => 'account_backup.copy_started',
            'correlation_id' => $correlationId,
        ]);

        $scope = app(ResolvesAccountBackupScope::class)->for($backup->account, $backup->node);

        app(RecordsProvisioningOperation::class)->record(
            $backup,
            app(ResolvesBackupCapableNode::class)->resolveFor($backup->node),
            ProvisioningVerb::Update,
            $backup->toCopyPayload($scope, $destination->toPayload(), $key),
            $correlationId,
            $backup->desired_state_version,
        );
    }

    /**
     * <prefix><account>/<date>-<kind>-<id>.tar.gz, a name that sorts by date and is unique.
     */
    private function objectKey(AccountBackup $backup, string $prefix): string
    {
        $prefix = ltrim($prefix, '/');

        if ($prefix !== '' && ! str_ends_with($prefix, '/')) {
            $prefix .= '/';
        }

        return $prefix.$backup->account->public_id.'/'.($backup->completed_at ?? $backup->created_at)->utc()->format('Y-m-d-His').'-'.$backup->kind.'-'.Str::substr($backup->uuid, 0, 8).'.tar.gz';
    }
}
