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
     * The node's fast lane (agent/internal/daemon fileops.go, every two seconds): its dispatched
     * operations for the fast-lane capabilities (ProvisioningOperation::FAST_LANE). Each is handed
     * out once: it is claimed here with a conditional update, so a poll that comes while it still
     * runs, or a second poll racing this one, never gets it again. One that is never reported is
     * failed by its deadline like any other. None of the heartbeat's own bookkeeping happens here.
     */
    public function poll(Request $request): JsonResponse
    {
        /** @var Node $node */
        $node = $request->attributes->get('node');

        $candidates = ProvisioningOperation::query()
            ->where('node_id', $node->id)
            ->whereIn('capability', ProvisioningOperation::FAST_LANE)
            ->where('status', ProvisioningStatus::Dispatched)
            ->whereNull('claimed_at')
            ->oldest('dispatched_at')
            ->limit(10)
            ->get();

        $pendingOperations = $candidates
            ->filter(fn (ProvisioningOperation $operation): bool => ProvisioningOperation::query()
                ->whereKey($operation->id)
                ->whereNull('claimed_at')
                ->update(['claimed_at' => now()]) === 1)
            ->map(fn (ProvisioningOperation $operation): array => $operation->toEnvelopeArray())
            ->values()
            ->all();

        return response()->json(['pending_operations' => $pendingOperations]);
    }
}
