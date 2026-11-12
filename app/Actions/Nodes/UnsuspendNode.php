<?php

namespace App\Actions\Nodes;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class UnsuspendNode
{
    public function handle(User $actor, Node $node): void
    {
        Gate::forUser($actor)->authorize('unsuspend', $node);

        if (! $node->isSuspended()) {
            return;
        }

        DB::transaction(function () use ($actor, $node): void {
            $node->unsuspend();

            // Reactivate only cascade-sourced capabilities. A manually-suspended capability
            // (suspension_source !== Cascade) stays suspended — this is decision 2's proof.
            $node->capabilities()
                ->suspendedByCascade()
                ->get()
                ->each(fn (NodeCapability $capability) => $capability->unsuspend());

            AuditEvent::record($node, 'node.unsuspended', $actor);
        });
    }
}
