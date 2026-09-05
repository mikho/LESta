<?php

namespace App\Actions\Nodes;

use App\Models\AuditEvent;
use App\Models\NodeCapability;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class UnsuspendNodeCapability
{
    public function handle(User $actor, NodeCapability $nodeCapability): void
    {
        Gate::forUser($actor)->authorize('unsuspend', $nodeCapability);

        if (! $nodeCapability->isSuspended()) {
            return;
        }

        DB::transaction(function () use ($actor, $nodeCapability): void {
            $nodeCapability->unsuspend();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $nodeCapability->getMorphClass(),
                'auditable_id' => $nodeCapability->getKey(),
                'action' => 'node_capability.unsuspended',
                'correlation_id' => (string) Str::uuid(),
            ]);
        });
    }
}
