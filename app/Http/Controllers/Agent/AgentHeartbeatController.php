<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\StoreHeartbeatRequest;
use App\Models\Node;
use App\Models\NodeCapability;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class AgentHeartbeatController extends Controller
{
    /**
     * Record a node's heartbeat: last_seen_at, reported agent/protocol version, and per-
     * capability liveness. Never creates NodeCapability rows, only updates rows that already
     * exist, matching this phase's own explicit boundary.
     */
    public function store(StoreHeartbeatRequest $request): JsonResponse
    {
        /** @var Node $node */
        $node = $request->attributes->get('node');

        $validated = $request->validated();

        $timestamp = Carbon::parse($validated['timestamp']);

        if (now()->diffInMinutes($timestamp, true) > 5) {
            return response()->json(['error' => 'Heartbeat timestamp is too far from server time.'], 422);
        }

        if ($node->last_seen_at !== null && $timestamp->lessThan($node->last_seen_at)) {
            // An out-of-order or replayed delivery, not a real problem: acknowledge without
            // touching state, so a newer heartbeat already recorded is never overwritten.
            return response()->json(['ack' => true, 'next_heartbeat_seconds' => 60]);
        }

        DB::transaction(function () use ($node, $validated, $timestamp): void {
            $node->forceFill([
                'last_seen_at' => $timestamp,
                'protocol_version' => $validated['protocol_version'],
                'agent_version' => $validated['agent_version'],
            ])->save();

            foreach ($validated['capabilities'] ?? [] as $entry) {
                NodeCapability::query()
                    ->where('node_id', $node->id)
                    ->where('capability', $entry['capability'])
                    ->update(['last_seen_at' => now()]);
            }
        });

        return response()->json(['ack' => true, 'next_heartbeat_seconds' => 60]);
    }
}
