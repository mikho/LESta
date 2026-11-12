<?php

namespace App\Actions\Nodes;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\NodeServiceAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Opens or closes the ssh port (22) on a node. The node's agent changes its own firewall, and the
 * panel shows the port as open or closed once the node reports back. The agent reaches the panel by
 * polling it, so a closed port never stops the panel from opening it again. Closing also stops
 * SFTP for every account on the node, and leaves sessions that are already open alone. Anyone who
 * may update the node may do this.
 */
class SetNodeSshAccess
{
    public function handle(User $actor, Node $node, bool $open): NodeServiceAction
    {
        Gate::forUser($actor)->authorize('update', $node);

        return DB::transaction(function () use ($actor, $node, $open): NodeServiceAction {
            $action = $open ? 'open' : 'close';

            $serviceAction = NodeServiceAction::query()->create([
                'node_id' => $node->id,
                'user_id' => $actor->id,
                'unit' => NodeServiceAction::SSH_UNIT,
                'action' => $action,
            ]);

            $correlationId = (string) Str::uuid();

            AuditEvent::record($serviceAction, "node_ssh.{$action}", $actor, $correlationId);

            app(RecordsProvisioningOperation::class)->record(
                $serviceAction,
                NodeServiceAction::SSH_CAPABILITY,
                ProvisioningVerb::Update,
                ['open' => $open],
                $correlationId,
            );

            return $serviceAction;
        });
    }
}
