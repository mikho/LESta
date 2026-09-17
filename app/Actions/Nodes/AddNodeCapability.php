<?php

namespace App\Actions\Nodes;

use App\Enums\NodeCapabilityStatus;
use App\Enums\NodeCapabilityType;
use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AddNodeCapability
{
    public function handle(User $actor, Node $node, string $capability): NodeCapability
    {
        Gate::forUser($actor)->authorize('create', [NodeCapability::class, $node]);

        if (NodeCapabilityType::tryFrom($capability) === null) {
            throw ValidationException::withMessages([
                'capability' => 'This is not a recognized capability.',
            ]);
        }

        return DB::transaction(function () use ($actor, $node, $capability): NodeCapability {
            try {
                // status is set explicitly via forceFill (it is deliberately not in
                // NodeCapability's own fillable list, mirroring suspended_at/suspension_source)
                // rather than left to the column's own DB default: Eloquent's create() never
                // re-fetches a freshly-inserted row, so a DB-only default would silently leave
                // the in-memory $nodeCapability->status null right after this call (the exact
                // "create() doesn't reflect DB-computed defaults" bug class this project has hit
                // before) even though the real database row would be correct.
                $nodeCapability = $node->capabilities()->make(['capability' => $capability]);
                $nodeCapability->forceFill(['status' => NodeCapabilityStatus::NotInstalled]);
                $nodeCapability->save();
            } catch (QueryException $e) {
                if ($e->getCode() !== '23000') {
                    throw $e;
                }

                throw ValidationException::withMessages([
                    'capability' => 'This node already has that capability.',
                ]);
            }

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $nodeCapability->getMorphClass(),
                'auditable_id' => $nodeCapability->getKey(),
                'action' => 'node_capability.added',
                'correlation_id' => (string) Str::uuid(),
            ]);

            return $nodeCapability;
        });
    }
}
