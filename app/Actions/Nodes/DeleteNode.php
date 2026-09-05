<?php

namespace App\Actions\Nodes;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DeleteNode
{
    public function handle(User $actor, Node $node): void
    {
        Gate::forUser($actor)->authorize('delete', $node);

        $hasDependents = $node->webDomains()->exists()
            || $node->dnsZones()->exists()
            || $node->cronJobs()->exists()
            || $node->tenantDatabases()->exists()
            || $node->provisioningOperations()->exists();

        if ($hasDependents) {
            throw ValidationException::withMessages([
                'node' => 'This node has dependent resources and cannot be deleted.',
            ]);
        }

        DB::transaction(function () use ($actor, $node): void {
            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $node->getMorphClass(),
                'auditable_id' => $node->getKey(),
                'action' => 'node.deleted',
                'correlation_id' => (string) Str::uuid(),
            ]);

            $node->delete();
        });
    }
}
