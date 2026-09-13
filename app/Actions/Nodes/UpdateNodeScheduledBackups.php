<?php

namespace App\Actions\Nodes;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Toggles Node::backups_scheduled, the opt-in flag CreateScheduledBackups reads to decide which
 * nodes it dispatches a nightly backup for. Deliberately its own tiny action rather than folded
 * into UpdateNode's own name/hostname payload: a single-click toggle, mirroring Suspend/
 * UnsuspendNode's own UX, not a field bundled into a form that also requires re-submitting name
 * and hostname.
 */
class UpdateNodeScheduledBackups
{
    public function handle(User $actor, Node $node, bool $enabled): void
    {
        Gate::forUser($actor)->authorize('update', $node);

        DB::transaction(function () use ($actor, $node, $enabled): void {
            $node->forceFill(['backups_scheduled' => $enabled])->save();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $node->getMorphClass(),
                'auditable_id' => $node->getKey(),
                'action' => $enabled ? 'node.scheduled_backups_enabled' : 'node.scheduled_backups_disabled',
                'correlation_id' => (string) Str::uuid(),
            ]);
        });
    }
}
