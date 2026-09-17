<?php

namespace App\Http\Controllers\Usage;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\MailAccount;
use App\Models\TenantDatabase;
use App\Models\UsageSnapshot;
use App\Models\UsageSnapshotRollup;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UsageSnapshotController extends Controller
{
    /**
     * Show an account's own usage history: real, raw, per-collection-cycle snapshots (see
     * App\Console\Commands\CollectUsageMetrics) for the last 90 days, newest first, plus the
     * long-range monthly rollups App\Console\Commands\RollupUsageSnapshots computes from raw
     * snapshots before they age out of that window.
     *
     * With no ?account= query param, shows the current user's own account (the tenant-facing
     * path, unchanged since this controller's own first version). With one, shows THAT account's
     * usage instead -- the admin cross-account path, reached from the account admin page
     * (accounts/show.tsx), gated by the exact same UsageSnapshotPolicy::viewAny the tenant path
     * already uses: a plain member of some other account can never pass usage.view_any, so
     * passing an arbitrary account's public id here grants nothing a member of that OTHER account
     * couldn't already see through their own membership. Rollups are gated by the same check
     * (they are just a coarser view of the same account's own usage data, not a distinct
     * resource), so there is no separate UsageSnapshotRollupPolicy.
     */
    public function index(Request $request): Response
    {
        $accountPublicId = $request->string('account')->toString();

        $account = $accountPublicId !== ''
            ? Account::where('public_id', $accountPublicId)->firstOrFail()
            : $this->resolveAccount($request->user());

        // No account param and no account-scoped membership at all: nothing to be gated from,
        // just nothing to show yet -- mirrors the identical empty-state fix already applied to
        // Domains/DNS/Mail/TenantDatabases/CronJobs's own resolveAccount() (see NoAccountNotice).
        // The explicit ?account= admin path above still 404s on a bad public id, since that is a
        // real "this account doesn't exist" error, not "you have no account".
        if ($account === null) {
            return Inertia::render('usage/index', [
                'snapshots' => null,
                'rollups' => null,
                'viewingAccount' => null,
            ]);
        }

        Gate::authorize('viewAny', [UsageSnapshot::class, $account]);

        $snapshots = $account->usageSnapshots()
            ->with('snapshotable')
            ->orderByDesc('collected_at')
            ->paginate(20)
            ->withQueryString();

        $snapshots->through(fn (UsageSnapshot $snapshot): array => $this->presentForIndex($snapshot));

        $rollups = $account->usageSnapshotRollups()
            ->with('snapshotable')
            ->orderByDesc('period')
            ->paginate(20, ['*'], 'rollups_page')
            ->withQueryString();

        $rollups->through(fn (UsageSnapshotRollup $rollup): array => $this->presentRollupForIndex($rollup));

        return Inertia::render('usage/index', [
            'snapshots' => $snapshots,
            'rollups' => $rollups,
            'viewingAccount' => $accountPublicId !== '' ? [
                'public_id' => $account->public_id,
                'name' => $account->name,
            ] : null,
        ]);
    }

    private function resolveAccount(User $user): ?Account
    {
        return $user->memberships()->whereNotNull('account_id')->with('account')->first()?->account;
    }

    /**
     * Shape a usage snapshot for the index listing, including a human-readable label for its
     * own polymorphic snapshotable (a MailAccount, TenantDatabase, or WebDomain) since the raw
     * morph type/id pair means nothing to a user. No App\Http\Resources in this app; inline
     * shaping matches the existing precedent (NodeController).
     *
     * @return array<string, mixed>
     */
    private function presentForIndex(UsageSnapshot $snapshot): array
    {
        return [
            'uuid' => $snapshot->uuid,
            'resource_type' => $this->resourceType($snapshot),
            'resource_label' => $this->resourceLabel($snapshot),
            'disk_bytes' => $snapshot->disk_bytes,
            'request_count' => $snapshot->request_count,
            'bytes_sent' => $snapshot->bytes_sent,
            'collected_at' => $snapshot->collected_at->toIso8601String(),
        ];
    }

    /**
     * Shape a usage rollup for the index listing, mirroring presentForIndex exactly (same
     * resource_type/resource_label shaping, since both share the same polymorphic snapshotable),
     * with a period instead of a collected_at instant and *_sum/*_last field names instead of the
     * raw instantaneous ones.
     *
     * @return array<string, mixed>
     */
    private function presentRollupForIndex(UsageSnapshotRollup $rollup): array
    {
        return [
            'uuid' => $rollup->uuid,
            'resource_type' => $this->resourceType($rollup),
            'resource_label' => $this->resourceLabel($rollup),
            'disk_bytes_last' => $rollup->disk_bytes_last,
            'request_count_sum' => $rollup->request_count_sum,
            'bytes_sent_sum' => $rollup->bytes_sent_sum,
            'period' => $rollup->period->toDateString(),
        ];
    }

    private function resourceType(UsageSnapshot|UsageSnapshotRollup $model): string
    {
        return match ($model->snapshotable_type) {
            (new MailAccount)->getMorphClass() => 'mail_account',
            (new TenantDatabase)->getMorphClass() => 'tenant_database',
            (new WebDomain)->getMorphClass() => 'web_domain',
            default => 'unknown',
        };
    }

    private function resourceLabel(UsageSnapshot|UsageSnapshotRollup $model): string
    {
        $resource = $model->snapshotable;

        if ($resource === null) {
            return '(deleted resource)';
        }

        return match (true) {
            $resource instanceof MailAccount => $resource->local_part.'@'.$resource->mailDomain?->domain,
            $resource instanceof TenantDatabase => $resource->label ?? $resource->database_name,
            $resource instanceof WebDomain => $resource->domain,
            default => '(unknown resource)',
        };
    }
}
