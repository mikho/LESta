<?php

namespace App\Concerns;

use App\Models\Node;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * The provider admin's customer view for a resource list (domains, DNS zones, mail domains,
 * databases, cron jobs): every account's resources, grouped by node and then by account, with an
 * optional exact node-name filter, a free-text account filter, and a "problems only" filter
 * (suspended, or latest provisioning operation failed, rejected or degraded). Read-only by design:
 * a row is a summary, and acting for a customer stays on the account page.
 *
 * Paginated by resource row, then grouped, so a node or account group can continue on the next
 * page rather than the page size depending on how many accounts a node has.
 */
trait ListsCustomerResources
{
    private const int CUSTOMER_PAGE_SIZE = 50;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query  The resource's own query, unscoped by account.
     * @param  Closure(TModel): array<string, mixed>  $present  The resource's own index presenter.
     * @return array{groups: list<array{node: array{uuid: string, name: string}, totals: array{resources: int, accounts: int}, accounts: list<array{account: array{public_id: string, name: string}, items: list<array<string, mixed>>}>}>, nodes: list<string>, summary: array{resources: int|null, accounts: int, nodes: int}, filters: array{node: string, account: string, status: string}, pagination: array{current_page: int, last_page: int, prev_page_url: string|null, next_page_url: string|null, total: int}}
     */
    private function customerListing(Request $request, Builder $query, Closure $present, string $orderColumn): array
    {
        $table = $query->getModel()->getTable();
        $node = trim((string) $request->string('node'));
        $account = trim((string) $request->string('account'));
        $status = $request->string('status')->toString() === 'problems' ? 'problems' : '';

        $filtered = $query
            ->join('nodes', 'nodes.id', '=', "{$table}.node_id")
            ->join('accounts', 'accounts.id', '=', "{$table}.account_id")
            ->when($node !== '', fn (Builder $q) => $q->where('nodes.name', $node))
            ->when($account !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('accounts.name', 'like', '%'.$account.'%')
                ->orWhere('accounts.public_id', 'like', '%'.$account.'%')
                ->orWhere('accounts.contact_email', 'like', '%'.$account.'%')))
            ->when($status === 'problems', fn (Builder $q) => $this->onlyProblems($q, $table));

        // The aggregate queries start from a clean select list: the resource's own query may carry
        // columns (withCount subselects) that a GROUP BY under ONLY_FULL_GROUP_BY would reject.
        $nodeTotals = (clone $filtered)->toBase()
            ->select([])
            ->groupBy('nodes.id')
            ->selectRaw("nodes.id as node_id, count(*) as resources, count(distinct {$table}.account_id) as accounts")
            ->get()
            ->keyBy('node_id');

        $paginator = (clone $filtered)
            ->select("{$table}.*")
            ->with(['node:id,uuid,name', 'account:id,public_id,name'])
            ->orderBy('nodes.name')
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
                'accounts' => $nodeRows->groupBy('account_id')
                    ->map(fn ($accountRows): array => [
                        'account' => ['public_id' => $accountRows->first()->account->public_id, 'name' => $accountRows->first()->account->name],
                        'items' => $accountRows->map($present)->values()->all(),
                    ])
                    ->values()
                    ->all(),
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
            'filters' => ['node' => $node, 'account' => $account, 'status' => $status],
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
     * A resource has a problem when it is suspended, or when its latest provisioning operation
     * (the same "latest" the status badge shows) failed, was rejected, or is degraded.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function onlyProblems(Builder $query, string $table): Builder
    {
        $morphClass = $query->getModel()->getMorphClass();

        return $query->where(fn (Builder $w) => $w
            ->whereNotNull("{$table}.suspended_at")
            ->orWhereExists(fn ($operation) => $operation->selectRaw('1')
                ->from('provisioning_operations as latest')
                ->whereColumn('latest.provisionable_id', "{$table}.id")
                ->where('latest.provisionable_type', $morphClass)
                ->whereIn('latest.status', ['failed', 'rejected', 'degraded'])
                ->whereNotExists(fn ($newer) => $newer->selectRaw('1')
                    ->from('provisioning_operations as newer')
                    ->whereColumn('newer.provisionable_id', 'latest.provisionable_id')
                    ->whereColumn('newer.provisionable_type', 'latest.provisionable_type')
                    ->whereColumn('newer.id', '>', 'latest.id'))));
    }
}
