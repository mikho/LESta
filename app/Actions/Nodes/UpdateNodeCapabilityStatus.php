<?php

namespace App\Actions\Nodes;

use App\Enums\NodeCapabilityStatus;
use App\Models\AuditEvent;
use App\Models\NodeCapability;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateNodeCapabilityStatus
{
    public function handle(User $actor, NodeCapability $nodeCapability, NodeCapabilityStatus $status): void
    {
        Gate::forUser($actor)->authorize('updateStatus', $nodeCapability);

        // The one rule this whole feature exists to enforce: Running can only ever be set by a
        // real, confirmed agent heartbeat (AgentHeartbeatController::store), never an admin's
        // say-so. UpdateNodeCapabilityStatusRequest already excludes this value from valid input,
        // but this check stays here too as the actual, unbypassable boundary.
        if ($status === NodeCapabilityStatus::Running) {
            throw ValidationException::withMessages([
                'status' => 'Status can only become Running via a confirmed agent heartbeat, not a manual change.',
            ]);
        }

        if ($nodeCapability->status === $status) {
            return;
        }

        DB::transaction(function () use ($actor, $nodeCapability, $status): void {
            $nodeCapability->forceFill(['status' => $status])->save();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $nodeCapability->getMorphClass(),
                'auditable_id' => $nodeCapability->getKey(),
                'action' => 'node_capability.status_changed',
                'correlation_id' => (string) Str::uuid(),
            ]);
        });
    }
}
