<?php

namespace App\Actions\Nodes;

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
        Gate::forUser($actor)->authorize('create', NodeCapability::class);

        if (NodeCapabilityType::tryFrom($capability) === null) {
            throw ValidationException::withMessages([
                'capability' => 'This is not a recognized capability.',
            ]);
        }

        return DB::transaction(function () use ($actor, $node, $capability): NodeCapability {
            try {
                $nodeCapability = $node->capabilities()->create(['capability' => $capability]);
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
