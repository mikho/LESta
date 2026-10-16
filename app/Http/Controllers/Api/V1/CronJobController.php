<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Resources\Api\V1\CronJobResource;
use App\Models\Account;
use App\Models\CronJob;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

class CronJobController extends ApiController
{
    /** Runs shown with one cron job, newest first: the same window as the panel's edit page. */
    private const int EXECUTIONS_SHOWN = 20;

    public function index(Request $request, Account $account): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', [CronJob::class, $account]);

        return CronJobResource::collection($account->cronJobs()
            ->with('latestProvisioningOperation')
            ->orderBy('id')
            ->cursorPaginate($this->limit($request)));
    }

    public function show(Account $account, CronJob $cronJob): CronJobResource
    {
        Gate::authorize('view', $cronJob);

        return new CronJobResource($cronJob->load([
            'latestProvisioningOperation',
            'executions' => fn ($query) => $query->latest('started_at')->limit(self::EXECUTIONS_SHOWN),
        ]));
    }
}
