<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Actions\Accounts\AssignAccountToReseller;
use App\Actions\Accounts\CreateAccount;
use App\Actions\Accounts\DeleteAccount;
use App\Actions\Accounts\SuspendAccount;
use App\Actions\Accounts\UnassignAccountFromReseller;
use App\Actions\Accounts\UnsuspendAccount;
use App\Actions\Accounts\UpdateAccount;
use App\Http\Controllers\Api\V1\ApiController;
use App\Http\Requests\Api\V1\Admin\PatchAccountRequest;
use App\Http\Requests\Api\V1\Admin\StoreAccountRequest;
use App\Http\Resources\Api\V1\AccountResource;
use App\Models\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Every customer account, for platform administrators. The hidden platform account is never
 * listed or reachable here, as in the panel.
 */
class AccountController extends ApiController
{
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Account::class);

        $request->validate(['filter.search' => ['sometimes', 'string'], 'filter.suspended' => ['sometimes', 'boolean']]);
        $search = trim((string) $request->input('filter.search', ''));

        return AccountResource::collection(Account::query()
            ->where('is_platform', false)
            ->with(['package', 'resellerAccount'])
            ->withCount('memberships')
            ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query
                ->where('name', 'like', '%'.$search.'%')
                ->orWhere('contact_email', 'like', '%'.$search.'%')))
            ->when($request->has('filter.suspended'), fn ($query) => $request->boolean('filter.suspended') ? $query->whereNotNull('suspended_at') : $query->whereNull('suspended_at'))
            ->orderBy('id')
            ->cursorPaginate($this->limit($request)));
    }

    public function show(Account $account): AccountResource
    {
        Gate::authorize('viewAny', Account::class);
        $this->customer($account);

        return $this->fresh($account);
    }

    /**
     * A new account and its owner. An owner who has no login yet gets one, and an e-mail to set
     * their password, as from the panel.
     */
    public function store(StoreAccountRequest $request): JsonResponse
    {
        $account = app(CreateAccount::class)->handle($request->user(), $request->accountData());

        return $this->accepted($request, $account, $this->fresh($account), created: true);
    }

    public function update(PatchAccountRequest $request, Account $account): JsonResponse
    {
        $this->customer($account);

        app(UpdateAccount::class)->handle($request->user(), $account, $request->accountData());

        return $this->accepted($request, $account, $this->fresh($account));
    }

    /**
     * Suspends the account and, through the panel's cascade, each of its resources; every
     * resource's own change goes to its node as its own operation.
     */
    public function suspend(Request $request, Account $account): JsonResponse
    {
        $this->customer($account);

        app(SuspendAccount::class)->handle($request->user(), $account);

        return $this->accepted($request, $account, $this->fresh($account));
    }

    public function unsuspend(Request $request, Account $account): JsonResponse
    {
        $this->customer($account);

        app(UnsuspendAccount::class)->handle($request->user(), $account);

        return $this->accepted($request, $account, $this->fresh($account));
    }

    /**
     * Deletes the account with everything it has, as the panel's delete does: site files, sealed
     * backups and logins on the nodes are removed by the cascade.
     */
    public function destroy(Request $request, Account $account): JsonResponse
    {
        $this->customer($account);

        $request->validate(['confirm' => ['required', 'string', Rule::in([$account->public_id])]], [
            'confirm.in' => __('Send the account id as "confirm" to delete it.'),
        ]);

        app(DeleteAccount::class)->handle($request->user(), $account);

        return response()->json(['data' => null, 'operation' => null]);
    }

    public function assignReseller(Request $request, Account $account): JsonResponse
    {
        $this->customer($account);

        $data = $request->validate(['reseller_account_id' => ['required', 'string', Rule::exists('accounts', 'public_id')->where('is_platform', false)]]);

        app(AssignAccountToReseller::class)->handle($request->user(), $account, Account::query()->where('public_id', $data['reseller_account_id'])->firstOrFail());

        return $this->accepted($request, $account, $this->fresh($account));
    }

    public function unassignReseller(Request $request, Account $account): JsonResponse
    {
        $this->customer($account);

        app(UnassignAccountFromReseller::class)->handle($request->user(), $account);

        return $this->accepted($request, $account, $this->fresh($account));
    }

    private function customer(Account $account): void
    {
        abort_if($account->is_platform, 404);
    }

    private function fresh(Account $account): AccountResource
    {
        return new AccountResource($account->refresh()->load(['package', 'resellerAccount'])->loadCount('memberships'));
    }
}
