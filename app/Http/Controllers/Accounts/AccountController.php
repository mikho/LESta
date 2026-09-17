<?php

namespace App\Http\Controllers\Accounts;

use App\Actions\Accounts\AssignAccountToReseller;
use App\Actions\Accounts\CreateAccount;
use App\Actions\Accounts\DeleteAccount;
use App\Actions\Accounts\SuspendAccount;
use App\Actions\Accounts\UnassignAccountFromReseller;
use App\Actions\Accounts\UnsuspendAccount;
use App\Actions\Accounts\UpdateAccount;
use App\Actions\Support\ViewAccountAsSupport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\AssignAccountToResellerRequest;
use App\Http\Requests\Accounts\StoreAccountRequest;
use App\Http\Requests\Accounts\UpdateAccountRequest;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Package;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class AccountController extends Controller
{
    /**
     * Show the platform-wide account list. Gated by accounts.view_as_support, the same
     * permission ViewAccountAsSupport's own show() call below requires: browsing the list and
     * opening one account are the same "support visibility" concept (see AccountPolicy's own
     * doc comment on why this stays deliberately distinct from a plain "view" ability).
     */
    public function index(Request $request): Response
    {
        Gate::authorize('viewAny', Account::class);

        $search = trim((string) $request->string('search'));

        $accounts = Account::query()
            ->with('package')
            ->withCount('memberships')
            ->when(
                $search !== '',
                fn ($query) => $query->where('name', 'like', '%'.$search.'%')
                    ->orWhere('contact_email', 'like', '%'.$search.'%')
            )
            ->orderBy('name')
            ->paginate(15)
            ->withQueryString();

        $accounts->through(fn (Account $account): array => $this->presentForIndex($account));

        return Inertia::render('accounts/index', [
            'accounts' => $accounts,
            'search' => $search,
            'canCreate' => $request->user()->hasPermission('accounts.create'),
        ]);
    }

    /**
     * Show the form for creating a new hosting account. Platform-admin-only for this first
     * version -- a reseller creating their own managed accounts is a deliberately deferred,
     * separate future capability.
     */
    public function create(Request $request): Response
    {
        Gate::authorize('create', Account::class);

        $packages = Package::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return Inertia::render('accounts/create', [
            'packages' => $packages,
        ]);
    }

    /**
     * Store a newly created hosting account, binding an existing or brand-new user as its owner.
     */
    public function store(StoreAccountRequest $request): RedirectResponse
    {
        $account = app(CreateAccount::class)->handle($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account created.')]);

        return to_route('accounts.show', $account);
    }

    /**
     * List every account the current user is a real member of -- the self-service equivalent of
     * the platform-wide index() above, which stays support/admin-only.
     */
    public function mine(Request $request): Response
    {
        $accounts = $request->user()->memberships()
            ->whereNotNull('account_id')
            ->with('account.package')
            ->get()
            ->pluck('account')
            ->unique('id')
            ->values();

        return Inertia::render('accounts/mine', [
            'accounts' => $accounts->map(fn (Account $account): array => $this->presentForIndex($account))->all(),
        ]);
    }

    /**
     * Show a single account's own details. A real member (owner or otherwise, including via a
     * reseller fallback -- see AccountPolicy::view) reaches their own account with no audit
     * event; anyone else falls through to ViewAccountAsSupport, which authorizes (viewAsSupport)
     * and records the real, separately-audited support-view AuditEvent. Anyone who fails both
     * gets a 404, not a 403: distinguishing "exists, no access" from "doesn't exist" is exactly
     * the account-enumeration signal this route must never leak.
     */
    public function show(Request $request, Account $account): Response
    {
        $user = $request->user();

        if (Gate::forUser($user)->denies('view', $account)) {
            if (Gate::forUser($user)->denies('viewAsSupport', $account)) {
                abort(404);
            }

            app(ViewAccountAsSupport::class)->handle($user, $account);
        }

        $account->load([
            'package',
            'memberships.user',
            'memberships.role',
            'resellerAccount',
            'managedAccounts',
        ])->loadCount([
            'webDomains',
            'mailDomains',
            'tenantDatabases',
            'cronJobs',
        ]);

        $packages = Package::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']);

        return Inertia::render('accounts/show', [
            'account' => $this->presentForShow($account),
            'packages' => $packages,
            'canManageReseller' => $request->user()->hasPermission('accounts.update'),
            // Mirrors AccountPolicy::update/suspend/unsuspend/delete exactly (each is
            // owner-or-its-own-permission, not a single shared one) -- every one of
            // UpdateAccount/SuspendAccount/UnsuspendAccount/DeleteAccount already enforces this
            // server-side, so a plain member submitting any of these forms already gets a real
            // 403; these props only stop the UI from showing buttons that would fail.
            'canUpdateAccount' => Gate::forUser($user)->allows('update', $account),
            'canSuspendAccount' => Gate::forUser($user)->allows('suspend', $account),
            'canUnsuspendAccount' => Gate::forUser($user)->allows('unsuspend', $account),
            'canDeleteAccount' => Gate::forUser($user)->allows('delete', $account),
            'canInviteMembers' => Gate::forUser($user)->allows('create', [Membership::class, $account]),
            // MembershipPolicy::delete's own owner-or-permission check only depends on the acting
            // user and the account, never on which specific membership row is being removed, so
            // one account-scoped flag correctly represents every row's own real ability.
            'canRemoveMembers' => $user->hasAccountRole($account, 'owner') || $user->hasPermission('memberships.delete'),
        ]);
    }

    /**
     * Search for accounts eligible to become $account's reseller (by name or public id),
     * powering the assign-reseller autocomplete on the show page. Excludes itself and any
     * already reseller-managed account (AssignAccountToReseller's own anti-chaining rule for
     * whichever account becomes the reseller) -- a candidate that already manages other accounts
     * is still eligible, since one reseller normally manages several accounts at once.
     * AssignAccountToReseller's third rule (an account that already manages others cannot itself
     * become reseller-managed) is about $account, the fixed target, not about which candidate is
     * picked, so it isn't a candidate filter here at all: if it would reject the assignment, it
     * would reject it identically for every candidate, and that rejection still surfaces for real
     * at submit time regardless.
     */
    public function resellerCandidates(Request $request, Account $account): JsonResponse
    {
        Gate::authorize('update', $account);

        $search = trim((string) $request->string('q'));

        $candidates = Account::query()
            ->where('id', '!=', $account->id)
            ->whereNull('reseller_account_id')
            ->when(
                $search !== '',
                fn ($query) => $query->where(fn ($query) => $query
                    ->where('name', 'like', '%'.$search.'%')
                    ->orWhere('public_id', 'like', '%'.$search.'%')
                )
            )
            ->orderBy('name')
            ->limit(10)
            ->get(['public_id', 'name']);

        return response()->json(['candidates' => $candidates]);
    }

    /**
     * Assign the given account to be managed by the reseller account identified by the submitted
     * public id.
     */
    public function assignReseller(AssignAccountToResellerRequest $request, Account $account): RedirectResponse
    {
        $resellerAccount = Account::where('public_id', $request->validated('reseller_account_public_id'))->firstOrFail();

        app(AssignAccountToReseller::class)->handle($request->user(), $account, $resellerAccount);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reseller assigned.')]);

        return to_route('accounts.show', $account);
    }

    /**
     * Unassign the given account from its current reseller, if any.
     */
    public function unassignReseller(Request $request, Account $account): RedirectResponse
    {
        app(UnassignAccountFromReseller::class)->handle($request->user(), $account);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Reseller unassigned.')]);

        return to_route('accounts.show', $account);
    }

    /**
     * Update the given account's own name/contact email/package.
     */
    public function update(UpdateAccountRequest $request, Account $account): RedirectResponse
    {
        app(UpdateAccount::class)->handle($request->user(), $account, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account updated.')]);

        return to_route('accounts.show', $account);
    }

    /**
     * Suspend the given account.
     */
    public function suspend(Request $request, Account $account): RedirectResponse
    {
        app(SuspendAccount::class)->handle($request->user(), $account);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account suspended.')]);

        return back();
    }

    /**
     * Unsuspend the given account.
     */
    public function unsuspend(Request $request, Account $account): RedirectResponse
    {
        app(UnsuspendAccount::class)->handle($request->user(), $account);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account unsuspended.')]);

        return back();
    }

    /**
     * Delete the given account. DeleteAccount throws no ValidationException of its own (unlike
     * DeleteNode): every dependent resource is deleted as part of the same cascade, never merely
     * blocked, so a real accounts.delete grant always succeeds.
     */
    public function destroy(Request $request, Account $account): RedirectResponse
    {
        app(DeleteAccount::class)->handle($request->user(), $account);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Account deleted.')]);

        return to_route('accounts.index');
    }

    /**
     * Shape an account for the index listing. No App\Http\Resources in this app; inline shaping
     * matches the existing precedent (NodeController).
     *
     * @return array<string, mixed>
     */
    private function presentForIndex(Account $account): array
    {
        return [
            'public_id' => $account->public_id,
            'name' => $account->name,
            'contact_email' => $account->contact_email,
            'package_name' => $account->package?->name,
            'memberships_count' => $account->memberships_count,
            'suspended_at' => $account->suspended_at?->toIso8601String(),
        ];
    }

    /**
     * Shape an account, with its memberships and resource counts, for the show page.
     *
     * @return array<string, mixed>
     */
    private function presentForShow(Account $account): array
    {
        return [
            'public_id' => $account->public_id,
            'name' => $account->name,
            'contact_email' => $account->contact_email,
            'package_id' => $account->package_id,
            'package_name' => $account->package?->name,
            'suspended_at' => $account->suspended_at?->toIso8601String(),
            'suspension_source' => $account->suspension_source?->value,
            'created_at' => $account->created_at?->toIso8601String(),
            'web_domains_count' => $account->web_domains_count,
            'mail_domains_count' => $account->mail_domains_count,
            'tenant_databases_count' => $account->tenant_databases_count,
            'cron_jobs_count' => $account->cron_jobs_count,
            'memberships' => $account->memberships
                ->map(fn (Membership $membership): array => $this->presentMembership($membership))
                ->all(),
            'reseller_account_public_id' => $account->resellerAccount?->public_id,
            'reseller_account_name' => $account->resellerAccount?->name,
            'managed_accounts' => $account->managedAccounts
                ->map(fn (Account $managed): array => $this->presentManagedAccount($managed))
                ->all(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentManagedAccount(Account $account): array
    {
        return [
            'public_id' => $account->public_id,
            'name' => $account->name,
            'contact_email' => $account->contact_email,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentMembership(Membership $membership): array
    {
        return [
            'id' => $membership->id,
            'role_name' => $membership->role->name,
            'user_name' => $membership->user?->name,
            'user_email' => $membership->user?->email,
        ];
    }
}
