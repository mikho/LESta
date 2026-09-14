<?php

namespace App\Actions\Nodes;

use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\NodeAdminGrant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Delegates full admin of $node to the user identified by $email: platform-admin-only, checked
 * directly against the real nodes.update permission rather than via NodePolicy::update (which
 * also passes for an existing NodeAdminGrant holder on this same node) -- a delegated node admin
 * must never be able to create a further delegated admin of their own, so this deliberately does
 * not reuse the same "update this node" ability a node admin already has.
 */
class GrantNodeAdmin
{
    public function handle(User $actor, Node $node, string $email): NodeAdminGrant
    {
        if (! $actor->hasPermission('nodes.update')) {
            throw new AuthorizationException;
        }

        $user = User::where('email', $email)->first();

        if ($user === null) {
            throw ValidationException::withMessages([
                'email' => 'No user exists with that email address.',
            ]);
        }

        return DB::transaction(function () use ($actor, $node, $user): NodeAdminGrant {
            $grant = NodeAdminGrant::query()->firstOrCreate([
                'user_id' => $user->id,
                'node_id' => $node->id,
            ]);

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $grant->getMorphClass(),
                'auditable_id' => $grant->getKey(),
                'action' => 'node_admin_grant.created',
                'correlation_id' => (string) Str::uuid(),
            ]);

            return $grant;
        });
    }
}
