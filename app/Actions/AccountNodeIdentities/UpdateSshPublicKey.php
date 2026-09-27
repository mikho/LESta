<?php

namespace App\Actions\AccountNodeIdentities;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Enums\ProvisioningVerb;
use App\Models\AccountNodeIdentity;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Sets or clears the tenant's own SFTP login key on their account's identity for one node, and
 * dispatches a real system.account-identity.v1 Update operation for it -- real since step 2 of
 * Web Application Hosting Threat Model and Isolation Design.md: the Go capability now renders a
 * real sshd Match block plus authorized_keys file per account
 * (agent/internal/capability/identity/capability.go).
 */
class UpdateSshPublicKey
{
    public function handle(User $actor, AccountNodeIdentity $identity, ?string $sshPublicKey): AccountNodeIdentity
    {
        Gate::forUser($actor)->authorize('updateSshCredential', $identity);

        return DB::transaction(function () use ($actor, $identity, $sshPublicKey): AccountNodeIdentity {
            $identity->forceFill([
                'ssh_public_key' => $sshPublicKey,
                'desired_state_version' => $identity->desired_state_version + 1,
            ])->save();

            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $identity->getMorphClass(),
                'auditable_id' => $identity->getKey(),
                'action' => $sshPublicKey === null
                    ? 'account_node_identity.ssh_key_removed'
                    : 'account_node_identity.ssh_key_updated',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $identity,
                'system.account-identity.v1',
                ProvisioningVerb::Update,
                $identity->toProvisioningPayload(),
                $correlationId,
                $identity->desired_state_version,
            );

            return $identity;
        });
    }
}
