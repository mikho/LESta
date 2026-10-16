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
use Illuminate\Validation\ValidationException;

/**
 * Starts, stops or restarts one of a node's services. The node's root applier runs systemctl on the
 * unit; the result, and the next heartbeat, update the state shown in the panel. Anyone who may
 * update the node may do this (a provider admin with nodes.update, or the node's own admin).
 */
class ControlNodeService
{
    public function handle(User $actor, Node $node, string $unit, string $action): NodeServiceAction
    {
        Gate::forUser($actor)->authorize('update', $node);

        if (! in_array($action, NodeServiceAction::ACTIONS, true)) {
            throw ValidationException::withMessages(['action' => __('Choose start, stop or restart.')]);
        }

        $reported = collect($node->service_states ?? [])->pluck('name');

        if (! NodeServiceAction::isControllable($unit) || ! $reported->contains($unit)) {
            throw ValidationException::withMessages(['unit' => __('This service cannot be controlled from the panel.')]);
        }

        return DB::transaction(function () use ($actor, $node, $unit, $action): NodeServiceAction {
            $serviceAction = NodeServiceAction::query()->create([
                'node_id' => $node->id,
                'user_id' => $actor->id,
                'unit' => $unit,
                'action' => $action,
            ]);

            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $serviceAction->getMorphClass(),
                'auditable_id' => $serviceAction->getKey(),
                'action' => "node_service.{$action}",
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $serviceAction,
                NodeServiceAction::CAPABILITY,
                ProvisioningVerb::Update,
                ['unit' => $unit, 'action' => $action],
                $correlationId,
            );

            return $serviceAction;
        });
    }
}
