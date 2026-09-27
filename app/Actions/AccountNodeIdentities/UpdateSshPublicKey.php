<?php

namespace App\Actions\AccountNodeIdentities;

use App\Models\AccountNodeIdentity;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Sets or clears the tenant's own SFTP login key on their account's identity for one node.
 * Deliberately does NOT dispatch a provisioning operation: system.account-identity.v1's own Go
 * capability still only implements create/delete (agent/internal/capability/identity/
 * capability.go), so there is nothing on a node yet that would consume an update here. This
 * action exists to let the value be set and stored now, ready for step 2 of Web Application
 * Hosting Threat Model and Isolation Design.md's own build sequence (real SFTP) to start
 * dispatching real Update operations against it -- see that document before adding one here.
 */
class UpdateSshPublicKey
{
    public function handle(User $actor, AccountNodeIdentity $identity, ?string $sshPublicKey): AccountNodeIdentity
    {
        Gate::forUser($actor)->authorize('updateSshCredential', $identity);

        return DB::transaction(function () use ($actor, $identity, $sshPublicKey): AccountNodeIdentity {
            $identity->forceFill(['ssh_public_key' => $sshPublicKey])->save();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $identity->getMorphClass(),
                'auditable_id' => $identity->getKey(),
                'action' => $sshPublicKey === null
                    ? 'account_node_identity.ssh_key_removed'
                    : 'account_node_identity.ssh_key_updated',
                'correlation_id' => (string) Str::uuid(),
            ]);

            return $identity;
        });
    }
}
