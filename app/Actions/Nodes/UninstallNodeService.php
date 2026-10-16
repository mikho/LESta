<?php

namespace App\Actions\Nodes;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Enums\ProvisioningStatus;
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
 * Removes one LESta service from a node: the node's maintenance applier runs the installer's remove
 * mode (units stopped, packages purged, configuration deleted; a stateful service's data moved
 * aside, never deleted). Refused while customer resources on the node use the service or another
 * installed service needs it. When the node reports success, the node's capabilities for the
 * service are removed (RecordsNodeServiceRemoval).
 */
class UninstallNodeService
{
    public function __construct(private ResolvesNodeServiceRemoval $removal) {}

    public function handle(User $actor, Node $node, string $service): NodeServiceAction
    {
        Gate::forUser($actor)->authorize('update', $node);

        if (! in_array($service, $this->removal->installed($node), true)) {
            throw ValidationException::withMessages(['service' => __('This service is not installed on the node, or cannot be removed from the panel.')]);
        }

        $blockers = $this->removal->blockers($node, $service);

        if ($blockers !== []) {
            throw ValidationException::withMessages(['service' => __(':service cannot be removed: :reasons.', ['service' => $service, 'reasons' => implode('; ', $blockers)])]);
        }

        $pending = NodeServiceAction::query()
            ->where('node_id', $node->id)
            ->where('action', 'uninstall')
            ->whereHas('latestProvisioningOperation', fn ($query) => $query->whereIn('status', [ProvisioningStatus::Pending, ProvisioningStatus::Dispatched]))
            ->exists();

        if ($pending) {
            throw ValidationException::withMessages(['service' => __('Another service is being removed from this node. Wait until it has finished.')]);
        }

        return DB::transaction(function () use ($actor, $node, $service): NodeServiceAction {
            $action = NodeServiceAction::query()->create([
                'node_id' => $node->id,
                'user_id' => $actor->id,
                'unit' => $service,
                'action' => 'uninstall',
            ]);

            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $action->getMorphClass(),
                'auditable_id' => $action->getKey(),
                'action' => 'node_service.uninstall',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $action,
                NodeServiceAction::UNINSTALL_CAPABILITY,
                ProvisioningVerb::Delete,
                ['service' => $service, 'confirm_data_loss' => in_array($service, ResolvesNodeServiceRemoval::STATEFUL, true)],
                $correlationId,
            );

            return $action;
        });
    }
}
