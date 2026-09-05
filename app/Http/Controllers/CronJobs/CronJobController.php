<?php

namespace App\Http\Controllers\CronJobs;

use App\Actions\CronJobs\CreateCronJob;
use App\Actions\CronJobs\DeleteCronJob;
use App\Actions\CronJobs\SuspendCronJob;
use App\Actions\CronJobs\UnsuspendCronJob;
use App\Actions\CronJobs\UpdateCronJob;
use App\Exceptions\ResourceQuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CronJobs\StoreCronJobRequest;
use App\Http\Requests\CronJobs\UpdateCronJobRequest;
use App\Models\Account;
use App\Models\CronJob;
use App\Models\CronJobExecution;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CronJobController extends Controller
{
    /**
     * Show the account's cron job list.
     */
    public function index(Request $request): Response
    {
        $account = $this->resolveAccount($request->user());

        Gate::authorize('viewAny', [CronJob::class, $account]);

        $search = trim((string) $request->string('search'));

        $cronJobs = $account->cronJobs()
            ->when($search !== '', fn ($query) => $query->where('command', 'like', '%'.$search.'%'))
            ->orderBy('id')
            ->paginate(15)
            ->withQueryString();

        $cronJobs->through(fn (CronJob $cronJob): array => $this->presentForIndex($cronJob));

        return Inertia::render('cron-jobs/index', [
            'cronJobs' => $cronJobs,
            'search' => $search,
        ]);
    }

    /**
     * Show the form for creating a new cron job.
     */
    public function create(Request $request): Response
    {
        $account = $this->resolveAccount($request->user());

        Gate::authorize('create', [CronJob::class, $account]);

        return Inertia::render('cron-jobs/create');
    }

    /**
     * Store a newly created cron job.
     */
    public function store(StoreCronJobRequest $request): RedirectResponse
    {
        $account = $this->resolveAccount($request->user());

        try {
            app(CreateCronJob::class)->handle($request->user(), $account, $request->validated());
        } catch (ResourceQuotaExceededException $e) {
            throw ValidationException::withMessages(['command' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cron job created.')]);

        return to_route('cron-jobs.index');
    }

    /**
     * Show the form for editing a cron job.
     */
    public function edit(Request $request, CronJob $cronJob): Response
    {
        Gate::authorize('update', $cronJob);

        $cronJob->load([
            'latestProvisioningOperation',
            'executions' => fn ($query) => $query->latest('started_at')->limit(20),
        ]);

        return Inertia::render('cron-jobs/edit', [
            'cronJob' => $this->presentForEdit($cronJob),
        ]);
    }

    /**
     * Update the given cron job.
     */
    public function update(UpdateCronJobRequest $request, CronJob $cronJob): RedirectResponse
    {
        app(UpdateCronJob::class)->handle($request->user(), $cronJob, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cron job updated.')]);

        return to_route('cron-jobs.edit', $cronJob);
    }

    /**
     * Suspend the given cron job.
     */
    public function suspend(Request $request, CronJob $cronJob): RedirectResponse
    {
        app(SuspendCronJob::class)->handle($request->user(), $cronJob);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cron job suspended.')]);

        return back();
    }

    /**
     * Unsuspend the given cron job.
     */
    public function unsuspend(Request $request, CronJob $cronJob): RedirectResponse
    {
        app(UnsuspendCronJob::class)->handle($request->user(), $cronJob);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cron job unsuspended.')]);

        return back();
    }

    /**
     * Delete the given cron job.
     */
    public function destroy(Request $request, CronJob $cronJob): RedirectResponse
    {
        app(DeleteCronJob::class)->handle($request->user(), $cronJob);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Cron job deleted.')]);

        return to_route('cron-jobs.index');
    }

    /**
     * Resolve the acting account: the user's first account-scoped membership. There is no
     * account switcher yet, so a user belonging to multiple accounts is limited to the first
     * one, a known limitation, not a silent gap.
     */
    private function resolveAccount(User $user): Account
    {
        /** @var Account */
        return $user->memberships()->whereNotNull('account_id')->with('account')->firstOrFail()->account;
    }

    /**
     * Shape a cron job for the index listing. No App\Http\Resources in this app; inline shaping
     * matches the existing precedent (DnsZoneController).
     *
     * @return array<string, mixed>
     */
    private function presentForIndex(CronJob $cronJob): array
    {
        return [
            'uuid' => $cronJob->uuid,
            'minute' => $cronJob->minute,
            'hour' => $cronJob->hour,
            'day_of_month' => $cronJob->day_of_month,
            'month' => $cronJob->month,
            'day_of_week' => $cronJob->day_of_week,
            'command' => $cronJob->command,
            'suspended_at' => $cronJob->suspended_at?->toIso8601String(),
            'suspension_source' => $cronJob->suspension_source?->value,
            'provisioning_status' => $cronJob->latestProvisioningOperation?->status->value,
        ];
    }

    /**
     * Shape a cron job for the edit page.
     *
     * @return array<string, mixed>
     */
    private function presentForEdit(CronJob $cronJob): array
    {
        return [
            'uuid' => $cronJob->uuid,
            'minute' => $cronJob->minute,
            'hour' => $cronJob->hour,
            'day_of_month' => $cronJob->day_of_month,
            'month' => $cronJob->month,
            'day_of_week' => $cronJob->day_of_week,
            'command' => $cronJob->command,
            'suspended_at' => $cronJob->suspended_at?->toIso8601String(),
            'suspension_source' => $cronJob->suspension_source?->value,
            'provisioning_status' => $cronJob->latestProvisioningOperation?->status->value,
            'executions' => $cronJob->executions->map(fn (CronJobExecution $e): array => [
                'started_at' => $e->started_at->toIso8601String(),
                'finished_at' => $e->finished_at->toIso8601String(),
                'exit_code' => $e->exit_code,
                'output' => $e->output,
            ])->all(),
        ];
    }
}
