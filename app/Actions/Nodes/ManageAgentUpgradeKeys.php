<?php

namespace App\Actions\Nodes;

use App\Models\AgentUpgradeKey;
use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * The public keys nodes trust for agent upgrades (the private halves never reach the panel), and
 * recording which key a node trusts. Only platform administrators with nodes.update manage keys.
 */
class ManageAgentUpgradeKeys
{
    public function create(User $actor, string $name, string $publicKey): AgentUpgradeKey
    {
        $this->authorize($actor);

        if (! AgentUpgradeKey::isValidPublicKey($publicKey)) {
            throw ValidationException::withMessages(['public_key' => __('This is not a base64 Ed25519 public key.')]);
        }

        if (AgentUpgradeKey::query()->where('public_key', $publicKey)->exists()) {
            throw ValidationException::withMessages(['public_key' => __('This key is already registered.')]);
        }

        return DB::transaction(function () use ($actor, $name, $publicKey): AgentUpgradeKey {
            $key = AgentUpgradeKey::create(['name' => $name, 'public_key' => $publicKey, 'created_by' => $actor->id]);

            $this->audit($actor, $key, 'agent_upgrade_key.created');

            return $key;
        });
    }

    public function delete(User $actor, AgentUpgradeKey $key): void
    {
        $this->authorize($actor);

        if ($key->nodes()->exists()) {
            throw ValidationException::withMessages(['key' => __('This key is trusted by a node. Move the node to another key first.')]);
        }

        DB::transaction(function () use ($actor, $key): void {
            $this->audit($actor, $key, 'agent_upgrade_key.deleted');
            $key->delete();
        });
    }

    /**
     * Records that $node trusts $key (or none). This is the administrator's statement about what
     * was installed on the node; the node itself decides what it accepts.
     */
    public function assign(User $actor, Node $node, ?AgentUpgradeKey $key): void
    {
        Gate::forUser($actor)->authorize('update', $node);

        DB::transaction(function () use ($actor, $node, $key): void {
            $node->forceFill(['upgrade_key_id' => $key?->id])->save();

            $this->audit($actor, $node, 'node.upgrade_key_assigned');
        });
    }

    private function authorize(User $actor): void
    {
        abort_unless($actor->hasPermission('nodes.update'), 403);
    }

    private function audit(User $actor, AgentUpgradeKey|Node $subject, string $action): void
    {
        AuditEvent::record($subject, $action, $actor);
    }
}
