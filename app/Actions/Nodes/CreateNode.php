<?php

namespace App\Actions\Nodes;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateNode
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{name: string, hostname: string}
     */
    public function handle(User $actor, array $data): Node
    {
        Gate::forUser($actor)->authorize('create', Node::class);

        return DB::transaction(function () use ($actor, $data): Node {
            $node = Node::query()->create([
                'uuid' => (string) Str::uuid(),
                'name' => $data['name'],
                'hostname' => $data['hostname'],
            ]);

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $node->getMorphClass(),
                'auditable_id' => $node->getKey(),
                'action' => 'node.created',
                'correlation_id' => (string) Str::uuid(),
            ]);

            return $node;
        });
    }
}
