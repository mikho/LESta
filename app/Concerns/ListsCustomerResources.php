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
 * optional exact node-name filter and a free-text account filter. Read-only by design: a row is a
 * summary, and acting for a customer stays on the account page.
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
     * @return array{groups: list<array{node: array{uuid: string, name: string}, accounts: list<array{account: array{public_id: string, name: string}, items: list<array<string, mixed>>}>}>, nodes: list<string>, filters: array{node: string, account: string}, pagination: array{current_page: int, last_page: int, prev_page_url: string|null, next_page_url: string|null, total: int}}
     */
    private function customerListing(Request $request, Builder $query, Closure $present, string $orderColumn): array
    {
        $table = $query->getModel()->getTable();
        $node = trim((string) $request->string('node'));
        $account = trim((string) $request->string('account'));

        $paginator = $query
            ->select("{$table}.*")
            ->join('nodes', 'nodes.id', '=', "{$table}.node_id")
            ->join('accounts', 'accounts.id', '=', "{$table}.account_id")
            ->with(['node:id,uuid,name', 'account:id,public_id,name'])
            ->when($node !== '', fn (Builder $q) => $q->where('nodes.name', $node))
            ->when($account !== '', fn (Builder $q) => $q->where(fn (Builder $w) => $w
                ->where('accounts.name', 'like', '%'.$account.'%')
                ->orWhere('accounts.public_id', 'like', '%'.$account.'%')
                ->orWhere('accounts.contact_email', 'like', '%'.$account.'%')))
            ->orderBy('nodes.name')
            ->orderBy('accounts.name')
            ->orderBy("{$table}.{$orderColumn}")
            ->paginate(self::CUSTOMER_PAGE_SIZE)
            ->withQueryString();

        $groups = collect($paginator->items())
            ->groupBy('node_id')
            ->map(fn ($nodeRows): array => [
                'node' => ['uuid' => $nodeRows->first()->node->uuid, 'name' => $nodeRows->first()->node->name],
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

        return [
            'groups' => $groups,
            'nodes' => Node::query()->orderBy('name')->pluck('name')->all(),
            'filters' => ['node' => $node, 'account' => $account],
            'pagination' => [
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'prev_page_url' => $paginator->previousPageUrl(),
                'next_page_url' => $paginator->nextPageUrl(),
                'total' => $paginator->total(),
            ],
        ];
    }
}
