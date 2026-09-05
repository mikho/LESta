<?php

namespace App\Http\Controllers\Agent;

use App\Http\Controllers\Controller;
use App\Http\Requests\Agent\StoreCronExecutionsRequest;
use App\Models\CronJob;
use App\Models\CronJobExecution;
use App\Models\Node;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

class AgentCronExecutionController extends Controller
{
    /**
     * Ingest a batch of cron execution-history entries reported by a node's daemon. An entry
     * whose resource_id does not resolve to a CronJob actually owned by the authenticated node
     * is silently skipped (logged, not errored), so one bad entry never fails the whole batch.
     * firstOrCreate on the (cron_job_id, started_at) unique pair is the idempotency mechanism:
     * a resent batch naturally no-ops on rows already created.
     */
    public function store(StoreCronExecutionsRequest $request): JsonResponse
    {
        /** @var Node $node */
        $node = $request->attributes->get('node');

        $validated = $request->validated();

        $accepted = 0;

        foreach ($validated['executions'] as $entry) {
            $cronJob = CronJob::query()
                ->where('uuid', $entry['resource_id'])
                ->where('node_id', $node->id)
                ->first();

            if ($cronJob === null) {
                Log::warning('Skipping cron execution report for unresolved or unowned resource_id.', [
                    'node_id' => $node->id,
                    'resource_id' => $entry['resource_id'],
                ]);

                continue;
            }

            CronJobExecution::query()->firstOrCreate(
                [
                    'cron_job_id' => $cronJob->id,
                    'started_at' => $entry['started_at'],
                ],
                [
                    'finished_at' => $entry['finished_at'],
                    'exit_code' => $entry['exit_code'],
                    'output' => $entry['output'],
                ],
            );

            $accepted++;
        }

        return response()->json(['accepted' => $accepted]);
    }
}
