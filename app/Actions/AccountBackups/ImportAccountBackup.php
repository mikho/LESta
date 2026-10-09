<?php

namespace App\Actions\AccountBackups;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesBackupCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\Account;
use App\Models\AccountBackup;
use App\Models\AccountBackupImport;
use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Brings a standard account backup (the tar.gz a download or an off-node copy produces) back as an
 * ordinary backup of this account on a node, which the owner then restores like any other. The file
 * comes either from the owner's own storage (the node fetches it with a signed request) or from the
 * owner's computer (uploaded to the control plane first, then fetched by the node at a one-time
 * address). The node reads the whole file, refuses one that is not a LESta backup of this very
 * account, and seals it under a fresh key; see RecordsAccountBackupResult for the completion.
 */
class ImportAccountBackup
{
    public function fromStorage(User $actor, Account $account, Node $node, string $objectKey, ?string $label): AccountBackup
    {
        Gate::forUser($actor)->authorize('create', [AccountBackup::class, $account]);

        $destination = $account->backupDestination;

        if ($destination === null) {
            throw ValidationException::withMessages(['object_key' => __('Set up your own storage first.')]);
        }

        return DB::transaction(fn (): AccountBackup => $this->create($actor, $account, $node, $label, [
            'destination' => $destination->toPayload(),
            'object_key' => $objectKey,
        ]));
    }

    /**
     * Starts the import of a file that has finished uploading.
     */
    public function fromUpload(User $actor, AccountBackupImport $import, string $token): AccountBackup
    {
        Gate::forUser($actor)->authorize('create', [AccountBackup::class, $import->account]);

        return DB::transaction(function () use ($actor, $import, $token): AccountBackup {
            $backup = $this->create($actor, $import->account, $import->node, $import->label, [
                'source_url' => route('agent.account-backup-imports', ['token' => $token]),
            ]);

            $import->forceFill(['account_backup_id' => $backup->id])->save();

            return $backup;
        });
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function create(User $actor, Account $account, Node $node, ?string $label, array $source): AccountBackup
    {
        $capability = app(ResolvesBackupCapableNode::class)->resolveFor($node);

        if (app(CreateAccountBackup::class)->isBusy($account, $node)) {
            throw ValidationException::withMessages(['backup' => __('A backup or restore is already running for this account on :node.', ['node' => $node->name])]);
        }

        $backup = AccountBackup::query()->create([
            'account_id' => $account->id,
            'node_id' => $node->id,
            'label' => $label !== null && $label !== '' ? $label : __('Imported backup'),
            'kind' => 'imported',
            'encryption_key' => bin2hex(random_bytes(32)),
            'requested_parts' => AccountBackup::PARTS,
            'desired_state_version' => 1,
        ]);

        $correlationId = (string) Str::uuid();

        AuditEvent::create([
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->getKey(),
            'auditable_type' => $backup->getMorphClass(),
            'auditable_id' => $backup->getKey(),
            'action' => 'account_backup.import_started',
            'correlation_id' => $correlationId,
        ]);

        $scope = app(ResolvesAccountBackupScope::class)->for($account, $node);

        app(RecordsProvisioningOperation::class)->record($backup, $capability, ProvisioningVerb::Create, $backup->toImportPayload($scope, $source), $correlationId, 1);

        return $backup;
    }
}
