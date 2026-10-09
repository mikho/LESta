<?php

namespace App\Actions\AccountBackups;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesBackupCapableNode;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\Account;
use App\Models\AccountBackup;
use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Starts a self-service backup of one account's data on one node. One backup or restore runs per
 * account and node at a time. The node does the work and reports size, checksum and what it
 * captured (see RecordsAccountBackupResult).
 */
class CreateAccountBackup
{
    /**
     * @param  list<string>  $parts  files, databases and/or mail
     */
    public function handle(User $actor, Account $account, Node $node, array $parts, ?string $label = null): AccountBackup
    {
        Gate::forUser($actor)->authorize('create', [AccountBackup::class, $account]);

        return DB::transaction(fn (): AccountBackup => $this->create($actor, $account, $node, $parts, $label, 'manual', null, []));
    }

    /**
     * The schedule's counterpart to handle(): no actor to authorize or attribute.
     *
     * @param  list<string>  $parts
     */
    public function handleScheduled(Account $account, Node $node, array $parts): AccountBackup
    {
        return DB::transaction(fn (): AccountBackup => $this->create(null, $account, $node, $parts, __('Scheduled backup'), 'scheduled', null, []));
    }

    /**
     * The safety snapshot a restore takes first: when it completes, the restore of
     * $restoreAfter (the parts given) is dispatched.
     *
     * @param  list<string>  $parts
     * @param  list<string>  $restoreParts
     */
    public function forRestore(User $actor, AccountBackup $restoreAfter, array $parts, array $restoreParts): AccountBackup
    {
        return DB::transaction(fn (): AccountBackup => $this->create($actor, $restoreAfter->account, $restoreAfter->node, $parts, __('Before restoring :when', ['when' => ($restoreAfter->completed_at ?? now())->format('Y-m-d H:i')]), 'before_restore', $restoreAfter, $restoreParts));
    }

    /**
     * @param  list<string>  $parts
     * @param  list<string>  $restoreParts
     */
    private function create(?User $actor, Account $account, Node $node, array $parts, ?string $label, string $kind, ?AccountBackup $restoreAfter, array $restoreParts): AccountBackup
    {
        $parts = array_values(array_intersect(AccountBackup::PARTS, $parts));

        if ($parts === []) {
            throw ValidationException::withMessages(['parts' => __('Choose what to back up.')]);
        }

        $capability = app(ResolvesBackupCapableNode::class)->resolveFor($node);

        $resolver = app(ResolvesAccountBackupScope::class);
        $scope = $resolver->for($account, $node);

        if (array_values(array_intersect($parts, $resolver->availableParts($scope))) === []) {
            throw ValidationException::withMessages(['parts' => __('There is nothing to back up on :node for the chosen parts.', ['node' => $node->name])]);
        }

        if ($restoreAfter === null && $this->isBusy($account, $node)) {
            throw ValidationException::withMessages(['backup' => __('A backup or restore is already running for this account on :node.', ['node' => $node->name])]);
        }

        $backup = AccountBackup::query()->create([
            'account_id' => $account->id,
            'node_id' => $node->id,
            'label' => $label,
            'kind' => $kind,
            'encryption_key' => bin2hex(random_bytes(32)),
            'requested_parts' => $parts,
            'restore_after_id' => $restoreAfter?->id,
            'restore_parts' => $restoreAfter !== null ? $restoreParts : null,
            'desired_state_version' => 1,
        ]);

        $correlationId = (string) Str::uuid();

        AuditEvent::create([
            'actor_type' => $actor?->getMorphClass(),
            'actor_id' => $actor?->getKey(),
            'auditable_type' => $backup->getMorphClass(),
            'auditable_id' => $backup->getKey(),
            'action' => 'account_backup.created',
            'correlation_id' => $correlationId,
        ]);

        app(RecordsProvisioningOperation::class)->record($backup, $capability, ProvisioningVerb::Create, $backup->toCreatePayload($scope), $correlationId, 1);

        return $backup;
    }

    /**
     * Whether a backup is still being made, or a restore is running, for the account on the node.
     */
    public function isBusy(Account $account, Node $node): bool
    {
        return AccountBackup::query()
            ->where('account_id', $account->id)
            ->where('node_id', $node->id)
            ->where(fn ($query) => $query
                ->whereIn('status', [ProvisioningStatus::Pending->value, ProvisioningStatus::Dispatched->value])
                ->orWhere('last_restore_status', 'running'))
            ->exists();
    }
}
