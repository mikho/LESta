<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CronJobs\CreateCronJob;
use App\Actions\CronJobs\DeleteCronJob;
use App\Actions\CronJobs\SuspendCronJob;
use App\Actions\CronJobs\UnsuspendCronJob;
use App\Actions\CronJobs\UpdateCronJob;
use App\Http\Requests\CronJobs\StoreCronJobRequest;
use App\Http\Requests\CronJobs\UpdateCronJobRequest;
use App\Http\Resources\Api\V1\CronJobResource;
use App\Models\Account;
use App\Models\CronJob;
use Illuminate\Http\JsonResponse;
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

    public function store(StoreCronJobRequest $request, Account $account): JsonResponse
    {
        $cronJob = app(CreateCronJob::class)->handle($request->user(), $account, $request->validated());

        return $this->accepted($request, $cronJob, $this->fresh($cronJob));
    }

    /**
     * The panel's update already keeps every field left out, so it serves as a PATCH as it is.
     */
    public function update(UpdateCronJobRequest $request, Account $account, CronJob $cronJob): JsonResponse
    {
        app(UpdateCronJob::class)->handle($request->user(), $cronJob, $request->validated());

        return $this->accepted($request, $cronJob, $this->fresh($cronJob));
    }

    public function suspend(Request $request, Account $account, CronJob $cronJob): JsonResponse
    {
        app(SuspendCronJob::class)->handle($request->user(), $cronJob);

        return $this->accepted($request, $cronJob, $this->fresh($cronJob));
    }

    public function unsuspend(Request $request, Account $account, CronJob $cronJob): JsonResponse
    {
        app(UnsuspendCronJob::class)->handle($request->user(), $cronJob);

        return $this->accepted($request, $cronJob, $this->fresh($cronJob));
    }

    public function destroy(Request $request, Account $account, CronJob $cronJob): JsonResponse
    {
        app(DeleteCronJob::class)->handle($request->user(), $cronJob);

        return $this->accepted($request, $cronJob, null);
    }

    private function fresh(CronJob $cronJob): CronJobResource
    {
        return new CronJobResource($cronJob->refresh()->load('latestProvisioningOperation'));
    }
}
