<?php

namespace App\Actions\Nodes;

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
            $token = $node->issueEnrollmentToken();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $node->getMorphClass(),
                'auditable_id' => $node->getKey(),
                'action' => 'node.enrollment_token_issued',
                'correlation_id' => (string) Str::uuid(),
            ]);

            return $token;
        });
    }
}
