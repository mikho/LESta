<?php

namespace App\Http\Controllers\Agent;

use App\Enums\ProvisioningStatus;
use App\Http\Controllers\Controller;
use App\Models\Node;
use App\Models\ProvisioningOperation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AgentFileOperationController extends Controller
{
    /**
     * files.manager.v1's own dedicated fast-lane poll: the exact same pending-operations query
     * AgentHeartbeatController::store() already runs, filtered to this one capability, with none
     * of that endpoint's own general heartbeat bookkeeping (Node::last_seen_at, per-capability
     * status tracking) -- those stay owned entirely by the general heartbeat loop, never touched
     * twice. Polled every 1-2 seconds (agent/internal/daemon's own fileops.go), so this returns
     * as little as possible, as fast as possible.
     */
    public function poll(Request $request): JsonResponse
    {
        /** @var Node $node */
        $node = $request->attributes->get('node');

        $pendingOperations = ProvisioningOperation::query()
            ->where('node_id', $node->id)
            ->where('capability', 'files.manager.v1')
            ->where('status', ProvisioningStatus::Dispatched)
            ->oldest('dispatched_at')
            ->limit(10)
            ->get()
            ->map(fn (ProvisioningOperation $operation): array => $operation->toEnvelopeArray())
            ->all();

        return response()->json(['pending_operations' => $pendingOperations]);
    }
}
