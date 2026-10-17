<?php

namespace App\Http\Controllers\AuditLog;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\ApiToken;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The audit log: who changed what, when, and whether through the panel, an API token or the
 * platform itself. A platform user with audit_events.view_any reads every event; anyone else
 * reads the events of the accounts they are a member of (and the accounts their reseller account
 * manages). Events stay hash-chained and read-only (see AuditEvent and audit:verify-chain).
 */
class AuditLogController extends Controller
{
    private const int PAGE_SIZE = 25;

    public function index(Request $request): Response
    {
        $user = $request->user();
        $all = Gate::allows('viewAll', AuditEvent::class);
        $reachable = $all ? null : $this->reachableAccounts($user);

        $filters = [
            'account' => trim((string) $request->string('account')),
            'action' => trim((string) $request->string('action')),
            'via' => in_array($request->string('via')->toString(), ['panel', 'api', 'system'], true) ? $request->string('via')->toString() : '',
        ];

        $account = $filters['account'] === '' ? null : Account::query()->where('public_id', $filters['account'])->firstOrFail();

        if ($account !== null) {
            Gate::authorize('viewAccount', [AuditEvent::class, $account]);
        }

        $events = AuditEvent::query()
            ->with(['actor'])
            ->when($account !== null, fn (Builder $query) => $query->where('account_id', $account->id))
            ->when($account === null && $reachable !== null, fn (Builder $query) => $query->whereIn('account_id', $reachable->pluck('id')))
            ->when($filters['action'] !== '', fn (Builder $query) => $query->where('action', 'like', $filters['action'].'%'))
            ->when($filters['via'] === 'api', fn (Builder $query) => $query->whereNotNull('api_token_id'))
            ->when($filters['via'] === 'panel', fn (Builder $query) => $query->whereNull('api_token_id')->whereNotNull('actor_id'))
            ->when($filters['via'] === 'system', fn (Builder $query) => $query->whereNull('actor_id'))
            ->orderByDesc('id')
            ->paginate(self::PAGE_SIZE)
            ->withQueryString();

        $tokenNames = ApiToken::query()->whereIn('id', $events->getCollection()->pluck('api_token_id')->filter()->unique())->pluck('name', 'id');
        $accountsById = Account::query()->whereIn('id', $events->getCollection()->pluck('account_id')->filter()->unique())->get(['id', 'public_id', 'name'])->keyBy('id');

        $events->through(fn (AuditEvent $event): array => $this->present($event, $all, $tokenNames, $accountsById));

        return Inertia::render('audit-log/index', [
            'events' => $events,
            'filters' => $filters,
            'accounts' => $all ? null : $reachable->map(fn (Account $reached): array => ['public_id' => $reached->public_id, 'name' => $reached->name])->values()->all(),
            'viewingAll' => $all,
        ]);
    }

    /**
     * @return Collection<int, Account>
     */
    private function reachableAccounts(User $user): Collection
    {
        $memberOf = $user->memberships()->whereNotNull('account_id')->pluck('account_id');

        return Account::query()
            ->where(fn (Builder $query) => $query->whereIn('id', $memberOf)->orWhereIn('reseller_account_id', $memberOf))
            ->orderBy('name')
            ->get(['id', 'public_id', 'name']);
    }

    /**
     * @param  Collection<int, string>  $tokenNames
     * @param  Collection<int, Account>  $accountsById
     * @return array<string, mixed>
     */
    private function present(AuditEvent $event, bool $all, Collection $tokenNames, Collection $accountsById): array
    {
        $actor = $event->actor;
        $account = $event->account_id === null ? null : $accountsById->get($event->account_id);

        return [
            'id' => $event->id,
            'at' => $event->created_at?->toIso8601String(),
            'action' => $event->action,
            'resource' => Str::of(class_basename(Model::getActualClassNameForMorph($event->auditable_type)))->snake(' ')->ucfirst()->toString(),
            'account' => $account === null ? null : ['public_id' => $account->public_id, 'name' => $account->name],
            // A customer reading their own log sees that platform staff acted, not who.
            'actor' => match (true) {
                $actor === null => null,
                ! $all && $actor instanceof User && $actor->isPlatformUser() => ['name' => __('Platform administrator'), 'email' => null],
                $actor instanceof User => ['name' => $actor->name, 'email' => $actor->email],
                default => ['name' => class_basename($actor), 'email' => null],
            },
            'via' => match (true) {
                $event->api_token_id !== null => ['kind' => 'api', 'token' => $tokenNames->get($event->api_token_id)],
                $event->actor_id === null => ['kind' => 'system', 'token' => null],
                default => ['kind' => 'panel', 'token' => null],
            },
            'ip_address' => $all ? $event->ip_address : null,
        ];
    }
}
