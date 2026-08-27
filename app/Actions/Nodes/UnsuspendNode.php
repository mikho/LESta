<?php

namespace App\Actions\Nodes;

use App\Enums\SuspensionSource;
use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

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
                ->where('suspension_source', SuspensionSource::Cascade)
                ->get()
                ->each(fn (NodeCapability $capability) => $capability->unsuspend());

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $node->getMorphClass(),
                'auditable_id' => $node->getKey(),
                'action' => 'node.unsuspended',
                'correlation_id' => (string) Str::uuid(),
            ]);
        });
    }
}
