<?php

namespace App\Http\Controllers\Agent;

use App\Actions\Provisioning\CompletesProvisioningOperation;
use App\Enums\ProvisioningStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\StoreOperationResultsRequest;
use App\Models\Node;
use App\Models\ProvisioningOperation;
use App\Services\Provisioning\ProvisioningResult;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class AgentOperationResultController extends Controller
{
    /**
     * Ingest a batch of provisioning-operation results reported by a node's daemon. An entry
     * whose idempotency_key does not resolve to a ProvisioningOperation actually Dispatched to
     * the authenticated node is silently skipped (logged, not errored), so one bad or
     * already-completed entry never fails the whole batch; this is also what makes resending
     * the same batch safe, since a second attempt finds the row already non-Dispatched.
     */
    public function store(StoreOperationResultsRequest $request): JsonResponse
    {
        /** @var Node $node */
        $node = $request->attributes->get('node');

        $validated = $request->validated();

        $accepted = 0;

        foreach ($validated['results'] as $entry) {
            $operation = ProvisioningOperation::query()
                ->where('idempotency_key', $entry['idempotency_key'])
                ->where('node_id', $node->id)
                ->where('status', ProvisioningStatus::Dispatched)
                ->first();

            if ($operation === null) {
                Log::warning('Skipping operation result report for unresolved or non-dispatched idempotency_key.', [
                    'node_id' => $node->id,
                    'idempotency_key' => $entry['idempotency_key'],
                ]);

                continue;
            }

            $result = new ProvisioningResult(
                status: ProvisioningStatus::from($entry['status']),
                observedStateVersion: $entry['observed_state_version'],
                observedStateDigest: $entry['observed_state_digest'],
                generationId: $entry['generation_id'],
                errors: $entry['errors'],
                completedAt: Carbon::parse($entry['completed_at']),
            );

            app(CompletesProvisioningOperation::class)->handle($operation, $result);

            $accepted++;
        }

        return response()->json(['accepted' => $accepted]);
    }
}
