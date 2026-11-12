<?php

namespace App\Actions\Nodes;

use App\Enums\SuspensionSource;
use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class SuspendNode
{
    public function handle(User $actor, Node $node): void
    {
        Gate::forUser($actor)->authorize('suspend', $node);

        if ($node->isSuspended()) {
            return;
        }

        DB::transaction(function () use ($actor, $node): void {
            $node->suspend(SuspensionSource::Manual);

            // Cascade: only capabilities that are currently active get cascade-suspended.
            // A capability already suspended for any reason (including manually, before this
            // call) is left completely untouched — its existing suspended_at/suspension_source
            // is preserved, which is exactly what lets it stay suspended through a later
            // node-level unsuspend.
            $node->capabilities()
                ->whereNull('suspended_at')
                ->get()
                ->each(fn (NodeCapability $capability) => $capability->suspend(SuspensionSource::Cascade));

            AuditEvent::record($node, 'node.suspended', $actor);
        });
    }
}
