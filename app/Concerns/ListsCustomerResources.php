<?php

namespace App\Concerns;

use App\Models\Node;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The provider admin's customer view for a resource list (domains, DNS zones, mail domains,
 * databases, cron jobs): every account's resources, grouped by node, one ordered list of rows per
 * node, each row carrying its owning account. Filterable by exact node name, by an account text
 * match, and by "problems only" (suspended, or latest provisioning operation failed, rejected or
 * degraded, plus whatever the resource's own extra problem rule adds), and sortable by an
 * allow-listed column within each node. Read-only by design: a row is a summary, and acting for a
 * customer stays on the account page.
 *
 * Paginated by resource row, then grouped, so a node group can continue on the next page rather
 * than the page size depending on how many accounts a node has.
 */
trait ListsCustomerResources
{
    private const int CUSTOMER_PAGE_SIZE = 50;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  The resource's own query, unscoped by account.
     * @param  Closure(TModel): array<string, mixed>  $present  The resource's own index presenter.
     * @param  array<string, string>  $sortable  Sort key (the `sort` query value) to SQL column.
     * @param  Closure(Builder<TModel>): mixed|null  $extraProblems  Adds this resource's own "problem" rules (orWhere conditions) to the problems-only filter.
     * @return array{groups: list<array{node: array{uuid: string, name: string}, totals: array{resources: int, accounts: int}, rows: list<array{account: array{public_id: string, name: string}, item: array<string, mixed>}>}>, nodes: list<string>, summary: array{resources: int|null, accounts: int, nodes: int}, filters: array{node: string, account: string, status: string, sort: string}, pagination: array{current_page: int, last_page: int, prev_page_url: string|null, next_page_url: string|null, total: int}}
     */
    private function customerListing(Request $request, Builder $query, Closure $present, string $orderColumn, array $sortable = [], ?Closure $extraProblems = null): array
    {
        $table = $query->getModel()->getTable();
        $node = trim((string) $request->string('node'));
        $account = trim((string) $request->string('account'));
        $status = $request->string('status')->toString() === 'problems' ? 'problems' : '';
        [$sortKey, $sortColumn, $sortDirection] = $this->resolveSort($request, $sortable);

        $filtered = $query
            ->join('nodes', 'nodes.id', '=', "{$table}.node_id")
            ->join('accounts', 'accounts.id', '=', "{$table}.account_id")
            ->when($node !== '', fn (Builder $q) => $q->where('nodes.name', $node))
            ->when($account !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('accounts.name', 'like', '%'.$account.'%')
                ->orWhere('accounts.public_id', 'like', '%'.$account.'%')
                ->orWhere('accounts.contact_email', 'like', '%'.$account.'%')))
            ->when($status === 'problems', fn (Builder $q) => $this->onlyProblems($q, $table, $extraProblems));

        // The aggregate queries start from a clean select list: the resource's own query may carry
        // columns (withCount subselects) that a GROUP BY under ONLY_FULL_GROUP_BY would reject.
        $nodeTotals = (clone $filtered)->toBase()
            ->select([])
            ->groupBy('nodes.id')
            ->selectRaw("nodes.id as node_id, count(*) as resources, count(distinct {$table}.account_id) as accounts")
            ->get()
            ->keyBy('node_id');

        $ordered = (clone $filtered)
            ->select("{$table}.*")
            ->with(['node:id,uuid,name', 'account:id,public_id,name', 'latestProvisioningOperation'])
            ->orderBy('nodes.name');

        if ($sortColumn !== null) {
            $ordered->orderBy($sortColumn, $sortDirection);
        }

        $paginator = $ordered
            ->orderBy('accounts.name')
            ->orderBy("{$table}.{$orderColumn}")
            ->paginate(self::CUSTOMER_PAGE_SIZE)
            ->withQueryString();

        $groups = collect($paginator->items())
            ->groupBy('node_id')
            ->map(fn ($nodeRows, $nodeId): array => [
                'node' => ['uuid' => $nodeRows->first()->node->uuid, 'name' => $nodeRows->first()->node->name],
                'totals' => [
                    'resources' => (int) ($nodeTotals->get($nodeId)?->resources ?? 0),
                    'accounts' => (int) ($nodeTotals->get($nodeId)?->accounts ?? 0),
                ],
                'rows' => $nodeRows->map(fn ($row): array => [
                    'account' => ['public_id' => $row->account->public_id, 'name' => $row->account->name],
                    'item' => $present($row) + ['provisioning_error' => $this->provisioningError($row)],
                ])->values()->all(),
            ])
            ->values()
            ->all();

        $overall = (clone $filtered)->toBase()
            ->select([])
            ->selectRaw("count(*) as resources, count(distinct {$table}.account_id) as accounts, count(distinct {$table}.node_id) as nodes")
            ->first();

        return [
            'groups' => $groups,
            'nodes' => Node::query()->orderBy('name')->pluck('name')->all(),
            'summary' => [
                'resources' => (int) $overall->resources,
                'accounts' => (int) $overall->accounts,
                'nodes' => (int) $overall->nodes,
            ],
            'filters' => ['node' => $node, 'account' => $account, 'status' => $status, 'sort' => $sortKey],
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
     * The `sort` query value is a key from the allow-list, optionally prefixed with "-" for
     * descending. Anything else is ignored, so the value is never used as SQL.
     *
     * @param  array<string, string>  $sortable
     * @return array{0: string, 1: string|null, 2: 'asc'|'desc'}
     */
    private function resolveSort(Request $request, array $sortable): array
    {
        $raw = $request->string('sort')->toString();
        $direction = str_starts_with($raw, '-') ? 'desc' : 'asc';
        $key = ltrim($raw, '-');

        if (! array_key_exists($key, $sortable)) {
            return ['', null, 'asc'];
        }

        return [($direction === 'desc' ? '-' : '').$key, $sortable[$key], $direction];
    }

    /**
     * The reason a resource's latest provisioning operation did not succeed, when it did not:
     * the first error message the node reported, or null when the operation is healthy, still in
     * flight, or reported no message.
     *
     * @param  Model  $resource
     */
    private function provisioningError($resource): ?string
    {
        $operation = $resource->latestProvisioningOperation;

        if ($operation === null || ! in_array($operation->status->value, ['failed', 'rejected', 'degraded'], true)) {
            return null;
        }

        $message = $operation->errors[0]['message'] ?? null;

        return is_string($message) ? $message : null;
    }

    /**
     * A resource has a problem when it is suspended, when its latest provisioning operation (the
     * same "latest" the status badge shows) failed, was rejected, or is degraded, or when the
     * resource's own extra rule says so.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  Closure(Builder<TModel>): mixed|null  $extraProblems
     * @return Builder<TModel>
     */
    private function onlyProblems(Builder $query, string $table, ?Closure $extraProblems): Builder
    {
        $morphClass = $query->getModel()->getMorphClass();

        return $query->where(function (Builder $w) use ($table, $morphClass, $extraProblems): void {
            $w->whereNotNull("{$table}.suspended_at")
                ->orWhereExists(fn ($operation) => $operation->selectRaw('1')
                    ->from('provisioning_operations as latest')
                    ->whereColumn('latest.provisionable_id', "{$table}.id")
                    ->where('latest.provisionable_type', $morphClass)
                    ->whereIn('latest.status', ['failed', 'rejected', 'degraded'])
                    ->whereNotExists(fn ($newer) => $newer->selectRaw('1')
                        ->from('provisioning_operations as newer')
                        ->whereColumn('newer.provisionable_id', 'latest.provisionable_id')
                        ->whereColumn('newer.provisionable_type', 'latest.provisionable_type')
                        ->whereColumn('newer.id', '>', 'latest.id')));

            if ($extraProblems !== null) {
                $extraProblems($w);
            }
        });
    }
}
