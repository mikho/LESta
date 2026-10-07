<?php

namespace App\Http\Controllers\Usage;

use App\Concerns\ResolvesCurrentAccount;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\MailAccount;
use App\Models\Node;
use App\Models\TenantDatabase;
use App\Models\UsageSnapshot;
use App\Models\UsageSnapshotRollup;
use App\Models\WebDomain;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class UsageSnapshotController extends Controller
{
    private const int CUSTOMER_PAGE_SIZE = 50;

    use ResolvesCurrentAccount;

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

        if ($accountPublicId === '' && $request->user()->can('viewAnyAcrossAccounts', UsageSnapshot::class)) {
            return Inertia::render('usage/index', [
                'snapshots' => null,
                'rollups' => null,
                'viewingAccount' => null,
                'customers' => $this->customerUsage($request),
            ]);
        }

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

    /**
     * The provider admin's usage summary: one row per node and account, grouped by node,
     * filterable by exact node name and by an account text match (the `search` query param here,
     * since `account` already selects a single account's detail page), and sortable within each
     * node by disk, requests, data sent or last collection. Disk is the sum of each resource's
     * latest reading (an instantaneous measurement), computed in SQL so it can be sorted;
     * requests and bytes sent are per-collection-cycle increments, so they are summed over the
     * last 30 days. Each row's account links to that account's own full usage page.
     *
     * @return array{groups: list<array{node: array{uuid: string, name: string}, totals: array{resources: int, accounts: int}, rows: list<array{account: array{public_id: string, name: string}, item: array<string, mixed>}>}>, nodes: list<string>, summary: array{resources: int|null, accounts: int, nodes: int}, filters: array{node: string, account: string, status: string, sort: string}, pagination: array{current_page: int, last_page: int, prev_page_url: string|null, next_page_url: string|null, total: int}}
     */
    private function customerUsage(Request $request): array
    {
        $node = trim((string) $request->string('node'));
        $search = trim((string) $request->string('search'));
        $sortable = ['disk' => 'disk_bytes', 'requests' => 'requests', 'sent' => 'bytes_sent', 'collected' => 'last_collected_at'];
        $raw = $request->string('sort')->toString();
        $sortKey = ltrim($raw, '-');
        $sortDirection = str_starts_with($raw, '-') ? 'desc' : 'asc';

        $filtered = UsageSnapshot::query()
            ->join('nodes', 'nodes.id', '=', 'usage_snapshots.node_id')
            ->join('accounts', 'accounts.id', '=', 'usage_snapshots.account_id')
            ->where('usage_snapshots.collected_at', '>=', now()->subDays(30))
            ->when($node !== '', fn (Builder $q) => $q->where('nodes.name', $node))
            ->when($search !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('accounts.name', 'like', '%'.$search.'%')
                ->orWhere('accounts.public_id', 'like', '%'.$search.'%')
                ->orWhere('accounts.contact_email', 'like', '%'.$search.'%')));

        $latestDisk = '(select sum(latest.disk_bytes) from usage_snapshots as latest'
            .' where latest.node_id = usage_snapshots.node_id and latest.account_id = usage_snapshots.account_id'
            .' and not exists (select 1 from usage_snapshots as newer'
            .' where newer.snapshotable_type = latest.snapshotable_type and newer.snapshotable_id = latest.snapshotable_id'
            .' and (newer.collected_at > latest.collected_at or (newer.collected_at = latest.collected_at and newer.id > latest.id)))) as disk_bytes';

        $ordered = (clone $filtered)
            ->groupBy('usage_snapshots.node_id', 'usage_snapshots.account_id', 'nodes.uuid', 'nodes.name', 'accounts.public_id', 'accounts.name')
            ->select([
                'usage_snapshots.node_id',
                'usage_snapshots.account_id',
                'nodes.uuid as node_uuid',
                'nodes.name as node_name',
                'accounts.public_id as account_public_id',
                'accounts.name as account_name',
            ])
            ->selectRaw('sum(usage_snapshots.request_count) as requests, sum(usage_snapshots.bytes_sent) as bytes_sent, max(usage_snapshots.collected_at) as last_collected_at')
            ->selectRaw($latestDisk)
            ->orderBy('nodes.name');

        if (array_key_exists($sortKey, $sortable)) {
            $ordered->orderBy($sortable[$sortKey], $sortDirection);
        } else {
            $sortKey = '';
        }

        $paginator = $ordered
            ->orderBy('accounts.name')
            ->paginate(self::CUSTOMER_PAGE_SIZE)
            ->withQueryString();

        $rows = collect($paginator->items());

        $pairs = (clone $filtered)->toBase()
            ->select('usage_snapshots.node_id', 'usage_snapshots.account_id')
            ->distinct()
            ->get();
        $accountsPerNode = $pairs->groupBy('node_id')->map->count();

        $groups = $rows
            ->groupBy('node_id')
            ->map(fn ($nodeRows): array => [
                'node' => ['uuid' => $nodeRows->first()->node_uuid, 'name' => $nodeRows->first()->node_name],
                'totals' => ['resources' => $accountsPerNode->get($nodeRows->first()->node_id, 0), 'accounts' => $accountsPerNode->get($nodeRows->first()->node_id, 0)],
                'rows' => $nodeRows->map(fn ($row): array => [
                    'account' => ['public_id' => $row->account_public_id, 'name' => $row->account_name],
                    'item' => [
                        'uuid' => $row->node_id.'-'.$row->account_id,
                        'account_public_id' => $row->account_public_id,
                        'disk_bytes' => $this->nullableInt($row->disk_bytes),
                        'request_count' => $this->nullableInt($row->requests),
                        'bytes_sent' => $this->nullableInt($row->bytes_sent),
                        'last_collected_at' => Carbon::parse($row->last_collected_at)->toIso8601String(),
                        'provisioning_error' => null,
                    ],
                ])->values()->all(),
            ])
            ->values()
            ->all();

        return [
            'groups' => $groups,
            'nodes' => Node::query()->orderBy('name')->pluck('name')->all(),
            'summary' => [
                'resources' => null,
                'accounts' => $pairs->pluck('account_id')->unique()->count(),
                'nodes' => $pairs->pluck('node_id')->unique()->count(),
            ],
            'filters' => ['node' => $node, 'account' => $search, 'status' => '', 'sort' => $sortKey === '' ? '' : ($sortDirection === 'desc' ? '-' : '').$sortKey],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'next_page_url' => $paginator->nextPageUrl(),
                'total' => $paginator->total(),
            ],
        ];
    }

    /**
     * SUM() comes back as a decimal string on MariaDB, and stays null when every addend was null
     * (a resource type that never fills that column).
     */
    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }
}
