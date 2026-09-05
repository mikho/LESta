<?php

namespace App\Http\Controllers\Agent;

use App\Enums\NodeEnrollmentStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\StoreEnrollmentRequest;
use App\Models\Node;
use Illuminate\Http\JsonResponse;

class AgentEnrollmentController extends Controller
{
    /**
     * Complete a node's enrollment: exchange a valid, unexpired enrollment token for a
     * long-lived node credential. This is the only unauthenticated agent-facing endpoint,
     * hence its own dedicated "agent-enroll" rate limiter (routes/agent.php).
     */
    public function store(StoreEnrollmentRequest $request): JsonResponse
    {
        $validated = $request->validated();

        $node = Node::query()->where('uuid', $validated['node_uuid'])->first();

        if ($node === null) {
            return response()->json(['error' => 'Node not found.'], 404);
        }

        $tokenIsValid = $node->enrollment_status === NodeEnrollmentStatus::Pending
            && $node->enrollment_token_hash !== null
            && $node->enrollment_token_expires_at !== null
            && $node->enrollment_token_expires_at->isFuture()
            && hash_equals($node->enrollment_token_hash, hash('sha256', $validated['enrollment_token']));

        if (! $tokenIsValid) {
            // Deliberately vague: never let a caller distinguish "wrong token" from "expired
            // token" from "wrong node", which would otherwise act as an oracle.
            return response()->json(['error' => 'Invalid or expired enrollment token.'], 422);
        }

        $credential = $node->completeEnrollment($validated['protocol_version'], $validated['agent_version']);

        if (isset($validated['hostname']) && $validated['hostname'] !== $node->hostname) {
            $node->forceFill(['hostname' => $validated['hostname']])->save();
        }

        return response()->json([
            'node_credential' => $credential,
            'heartbeat_interval_seconds' => 60,
            'protocol_version' => '1',
        ]);
    }
}
