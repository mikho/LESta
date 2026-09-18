<?php

namespace App\Actions\Nodes;

use App\Enums\NodeEnrollmentStatus;
use App\Models\AuditEvent;
use App\Models\Node;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class IssueNodeEnrollmentToken
{
    public function handle(User $actor, Node $node): string
    {
        Gate::forUser($actor)->authorize('update', $node);

        return DB::transaction(function () use ($actor, $node): string {
            // Node::issueEnrollmentToken() unconditionally revokes any existing
            // node_credential_hash too (not just a first-time-enrollment concern) -- captured
            // before the call so the audit event can tell "first enrollment" and "revoking a
            // live, already-working credential" apart. The latter is the real, previously
            // missing recovery path for a suspected-compromised node credential: an operator
            // suspecting compromise clicks the same "Issue enrollment token" action an
            // already-enrolled node, and the audit trail now says exactly that, not something
            // that reads like routine first-time setup.
            $wasEnrolled = $node->enrollment_status === NodeEnrollmentStatus::Enrolled;

            $token = $node->issueEnrollmentToken();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $node->getMorphClass(),
                'auditable_id' => $node->getKey(),
                'action' => $wasEnrolled ? 'node.credential_rotated' : 'node.enrollment_token_issued',
                'correlation_id' => (string) Str::uuid(),
            ]);

            return $token;
        });
    }
}
