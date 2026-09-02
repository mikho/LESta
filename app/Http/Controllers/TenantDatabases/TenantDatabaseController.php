<?php

namespace App\Http\Controllers\TenantDatabases;

use App\Actions\TenantDatabases\CreateTenantDatabase;
use App\Actions\TenantDatabases\DeleteTenantDatabase;
use App\Actions\TenantDatabases\RotateTenantDatabasePassword;
use App\Actions\TenantDatabases\SuspendTenantDatabase;
use App\Actions\TenantDatabases\UnsuspendTenantDatabase;
use App\Exceptions\ResourceQuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\TenantDatabases\StoreTenantDatabaseRequest;
use App\Models\Account;
use App\Models\TenantDatabase;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TenantDatabaseController extends Controller
{
    /**
     * Show the account's tenant database list.
     */
    public function index(Request $request): Response
    {
        $account = $this->resolveAccount($request->user());

        Gate::authorize('viewAny', [TenantDatabase::class, $account]);

        $search = trim((string) $request->string('search'));

        $tenantDatabases = $account->tenantDatabases()
            ->when($search !== '', fn ($query) => $query->where('label', 'like', '%'.$search.'%'))
            ->orderBy('label')
            ->paginate(15)
            ->withQueryString();

        $tenantDatabases->through(fn (TenantDatabase $tenantDatabase): array => $this->presentForIndex($tenantDatabase));

        return Inertia::render('tenant-databases/index', [
            'tenantDatabases' => $tenantDatabases,
            'search' => $search,
        ]);
    }

    /**
     * Show the form for creating a new tenant database.
     */
    public function create(Request $request): Response
    {
        $account = $this->resolveAccount($request->user());

        Gate::authorize('create', [TenantDatabase::class, $account]);

        return Inertia::render('tenant-databases/create');
    }

    /**
     * Store a newly created tenant database. Unlike every other resource controller in this
     * app, this redirects to the edit page (not the index) and flashes the one-time plaintext
     * password: the edit page is where TenantDatabaseController::edit() -- and its front end --
     * shows that password exactly once, via Inertia's own flash mechanism (see
     * presentForCreated()'s own doc comment).
     */
    public function store(StoreTenantDatabaseRequest $request): RedirectResponse
    {
        $account = $this->resolveAccount($request->user());

        try {
            [$tenantDatabase, $password] = app(CreateTenantDatabase::class)->handle($request->user(), $account, $request->validated());
        } catch (ResourceQuotaExceededException $e) {
            throw ValidationException::withMessages(['label' => $e->getMessage()]);
        }

        Inertia::flash('generatedPassword', $this->presentForCreated($tenantDatabase, $password));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tenant database created.')]);

        return to_route('tenant-databases.edit', $tenantDatabase);
    }

    /**
     * Show a tenant database's details.
     */
    public function edit(Request $request, TenantDatabase $tenantDatabase): Response
    {
        Gate::authorize('update', $tenantDatabase);

        $tenantDatabase->load('latestProvisioningOperation');

        return Inertia::render('tenant-databases/edit', [
            'tenantDatabase' => $this->presentForEdit($tenantDatabase),
        ]);
    }

    /**
     * Rotate the given tenant database's password. Takes no input: a fresh password is always
     * generated server-side, never supplied by the client.
     */
    public function rotatePassword(Request $request, TenantDatabase $tenantDatabase): RedirectResponse
    {
        [$tenantDatabase, $password] = app(RotateTenantDatabasePassword::class)->handle($request->user(), $tenantDatabase);

        Inertia::flash('generatedPassword', $this->presentForCreated($tenantDatabase, $password));
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tenant database password rotated.')]);

        return to_route('tenant-databases.edit', $tenantDatabase);
    }

    /**
     * Suspend the given tenant database.
     */
    public function suspend(Request $request, TenantDatabase $tenantDatabase): RedirectResponse
    {
        app(SuspendTenantDatabase::class)->handle($request->user(), $tenantDatabase);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tenant database suspended.')]);

        return back();
    }

    /**
     * Unsuspend the given tenant database.
     */
    public function unsuspend(Request $request, TenantDatabase $tenantDatabase): RedirectResponse
    {
        app(UnsuspendTenantDatabase::class)->handle($request->user(), $tenantDatabase);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tenant database unsuspended.')]);

        return back();
    }

    /**
     * Delete the given tenant database.
     */
    public function destroy(Request $request, TenantDatabase $tenantDatabase): RedirectResponse
    {
        app(DeleteTenantDatabase::class)->handle($request->user(), $tenantDatabase);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Tenant database deleted.')]);

        return to_route('tenant-databases.index');
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
     * Shape a tenant database for the index listing. Never includes the password: no App\Http\
     * Resources in this app, inline shaping matches the existing precedent (DnsZoneController).
     *
     * @return array<string, mixed>
     */
    private function presentForIndex(TenantDatabase $tenantDatabase): array
    {
        return [
            'uuid' => $tenantDatabase->uuid,
            'label' => $tenantDatabase->label,
            'database_name' => $tenantDatabase->database_name,
            'suspended_at' => $tenantDatabase->suspended_at?->toIso8601String(),
            'suspension_source' => $tenantDatabase->suspension_source?->value,
            'provisioning_status' => $tenantDatabase->latestProvisioningOperation?->status->value,
        ];
    }

    /**
     * Shape a tenant database for the edit page. Never includes the password: this is the
     * shape every subsequent page load gets, including a reload of the very page store()/
     * rotatePassword() just redirected to, once Inertia's one-shot flash for this request has
     * already been consumed.
     *
     * @return array<string, mixed>
     */
    private function presentForEdit(TenantDatabase $tenantDatabase): array
    {
        return [
            'uuid' => $tenantDatabase->uuid,
            'label' => $tenantDatabase->label,
            'database_name' => $tenantDatabase->database_name,
            'database_user' => $tenantDatabase->database_user,
            'suspended_at' => $tenantDatabase->suspended_at?->toIso8601String(),
            'suspension_source' => $tenantDatabase->suspension_source?->value,
            'provisioning_status' => $tenantDatabase->latestProvisioningOperation?->status->value,
        ];
    }

    /**
     * Shape the one-time reveal carried by store()'s and rotatePassword()'s own Inertia flash
     * (never a normal prop on any response): the only two shapes in this whole controller that
     * ever include a plaintext password, since a fresh password only ever exists in cleartext
     * for the single request that just generated or rotated it.
     *
     * @return array<string, mixed>
     */
    private function presentForCreated(TenantDatabase $tenantDatabase, string $password): array
    {
        return [
            'uuid' => $tenantDatabase->uuid,
            'label' => $tenantDatabase->label,
            'database_name' => $tenantDatabase->database_name,
            'database_user' => $tenantDatabase->database_user,
            'password' => $password,
        ];
    }
}
