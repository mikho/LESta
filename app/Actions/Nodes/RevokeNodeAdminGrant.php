<?php

namespace App\Actions\Nodes;

use App\Models\AuditEvent;
use App\Models\NodeAdminGrant;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Platform-admin-only, checked directly against the real nodes.update permission rather than via
 * NodePolicy::update -- see GrantNodeAdmin's own identical doc comment for why this must never
 * reuse the ability an existing NodeAdminGrant holder already has for their own node.
 */
class RevokeNodeAdminGrant
{
    public function handle(User $actor, NodeAdminGrant $grant): void
    {
        if (! $actor->hasPermission('nodes.update')) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $grant): void {
            $grantId = $grant->id;
            $grantMorphClass = $grant->getMorphClass();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $grantMorphClass,
                'auditable_id' => $grantId,
                'action' => 'node_admin_grant.revoked',
                'correlation_id' => (string) Str::uuid(),
            ]);

            $grant->delete();
        });
    }
}
