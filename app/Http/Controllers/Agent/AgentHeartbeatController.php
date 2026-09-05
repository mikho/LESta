<?php

namespace App\Http\Controllers\Agent;

use App\Enums\ProvisioningStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\StoreHeartbeatRequest;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
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

        $pendingOperations = ProvisioningOperation::query()
            ->where('node_id', $node->id)
            ->where('status', ProvisioningStatus::Dispatched)
            ->oldest('dispatched_at')
            ->limit(10)
            ->get()
            ->map(fn (ProvisioningOperation $operation): array => $this->presentAsEnvelope($operation))
            ->all();

        return response()->json([
            'ack' => true,
            'next_heartbeat_seconds' => 60,
            'pending_operations' => $pendingOperations,
        ]);
    }

    /**
     * Shape a ProvisioningOperation as the wire OperationEnvelope its owning node's agent
     * daemon expects, matching docs/protocol/operation-envelope.schema.json exactly.
     *
     * @return array<string, mixed>
     */
    private function presentAsEnvelope(ProvisioningOperation $operation): array
    {
        return [
            'protocol_version' => $operation->protocol_version,
            'capability' => $operation->capability,
            'operation' => $operation->operation->value,
            'resource_id' => $operation->resource_id,
            'desired_state_version' => $operation->desired_state_version,
            'idempotency_key' => $operation->idempotency_key,
            'correlation_id' => $operation->correlation_id,
            'deadline' => $operation->deadline?->toIso8601String(),
            'issued_at' => $operation->issued_at->toIso8601String(),
            'request_digest' => $operation->request_digest,
            'payload' => $operation->payload,
        ];
    }
}
