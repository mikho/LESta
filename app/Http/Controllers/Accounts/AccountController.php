<?php

namespace App\Http\Controllers\Accounts;

use App\Actions\Accounts\AssignAccountToReseller;
use App\Actions\Accounts\DeleteAccount;
use App\Actions\Accounts\SuspendAccount;
use App\Actions\Accounts\UnassignAccountFromReseller;
use App\Actions\Accounts\UnsuspendAccount;
use App\Actions\Accounts\UpdateAccount;
use App\Actions\Support\ViewAccountAsSupport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Accounts\AssignAccountToResellerRequest;
use App\Http\Requests\Accounts\UpdateAccountRequest;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Package;
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
        ]);
    }

    /**
     * Show a single account's own details. ViewAccountAsSupport itself authorizes (viewAsSupport)
     * and records the real, separately-audited support-view AuditEvent -- this controller never
     * duplicates either.
     */
    public function show(Request $request, Account $account): Response
    {
        app(ViewAccountAsSupport::class)->handle($request->user(), $account);

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
        ]);
    }

    /**
     * Assign the given account to be managed by the reseller account identified by the submitted
     * uuid.
     */
    public function assignReseller(AssignAccountToResellerRequest $request, Account $account): RedirectResponse
    {
        $resellerAccount = Account::where('uuid', $request->validated('reseller_account_uuid'))->firstOrFail();

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
            'uuid' => $account->uuid,
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
            'uuid' => $account->uuid,
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
            'reseller_account_uuid' => $account->resellerAccount?->uuid,
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
            'uuid' => $account->uuid,
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
