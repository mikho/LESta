<?php

namespace App\Actions\Nodes;

use App\Enums\SuspensionSource;
use App\Models\AuditEvent;
use App\Models\NodeCapability;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SuspendNodeCapability
{
    public function handle(User $actor, NodeCapability $nodeCapability): void
    {
        Gate::forUser($actor)->authorize('suspend', $nodeCapability);

        if ($nodeCapability->isSuspended()) {
            return;
        }

        DB::transaction(function () use ($actor, $nodeCapability): void {
            $nodeCapability->suspend(SuspensionSource::Manual);

            AuditEvent::record($nodeCapability, 'node_capability.suspended', $actor);
        });
    }
}
