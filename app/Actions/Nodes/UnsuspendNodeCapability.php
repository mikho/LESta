<?php

namespace App\Actions\Nodes;

use App\Models\AuditEvent;
use App\Models\NodeCapability;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

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

            AuditEvent::record($nodeCapability, 'node_capability.unsuspended', $actor);
        });
    }
}
