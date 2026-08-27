<?php

namespace App\Http\Controllers\Domains;

use App\Actions\Domains\CreateWebDomain;
use App\Actions\Domains\DeleteWebDomain;
use App\Actions\Domains\SuspendWebDomain;
use App\Actions\Domains\UnsuspendWebDomain;
use App\Actions\Domains\UpdateWebDomain;
use App\Exceptions\ResourceQuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Domains\StoreWebDomainRequest;
use App\Http\Requests\Domains\UpdateWebDomainRequest;
use App\Models\Account;
use App\Models\User;
use App\Models\WebDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WebDomainController extends Controller
{
    /**
     * Show the account's web domain list.
     */
    public function index(Request $request): Response
    {
        $account = $this->resolveAccount($request->user());

        Gate::authorize('viewAny', [WebDomain::class, $account]);

        $search = trim((string) $request->string('search'));

        $webDomains = $account->webDomains()
            ->with(['aliases', 'latestProvisioningOperation'])
            ->when($search !== '', fn ($query) => $query->where('domain', 'like', '%'.$search.'%'))
            ->orderBy('domain')
            ->paginate(15)
            ->withQueryString();

        $webDomains->through(fn (WebDomain $webDomain): array => $this->present($webDomain));

        return Inertia::render('domains/index', [
            'webDomains' => $webDomains,
            'search' => $search,
        ]);
    }

    /**
     * Show the form for creating a new web domain.
     */
    public function create(Request $request): Response
    {
        $account = $this->resolveAccount($request->user());

        Gate::authorize('create', [WebDomain::class, $account]);

        return Inertia::render('domains/create');
    }

    /**
     * Store a newly created web domain.
     */
    public function store(StoreWebDomainRequest $request): RedirectResponse
    {
        $account = $this->resolveAccount($request->user());

        try {
            app(CreateWebDomain::class)->handle($request->user(), $account, $request->validated());
        } catch (ResourceQuotaExceededException $e) {
            throw ValidationException::withMessages(['domain' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Web domain created.')]);

        return to_route('domains.index');
    }

    /**
     * Show the form for editing a web domain.
     */
    public function edit(Request $request, WebDomain $webDomain): Response
    {
        Gate::authorize('update', $webDomain);

        $webDomain->load(['aliases', 'latestProvisioningOperation']);

        return Inertia::render('domains/edit', [
            'webDomain' => $this->present($webDomain),
        ]);
    }

    /**
     * Update the given web domain.
     */
    public function update(UpdateWebDomainRequest $request, WebDomain $webDomain): RedirectResponse
    {
        app(UpdateWebDomain::class)->handle($request->user(), $webDomain, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Web domain updated.')]);

        return to_route('domains.edit', $webDomain);
    }

    /**
     * Suspend the given web domain.
     */
    public function suspend(Request $request, WebDomain $webDomain): RedirectResponse
    {
        app(SuspendWebDomain::class)->handle($request->user(), $webDomain);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Web domain suspended.')]);

        return back();
    }

    /**
     * Unsuspend the given web domain.
     */
    public function unsuspend(Request $request, WebDomain $webDomain): RedirectResponse
    {
        app(UnsuspendWebDomain::class)->handle($request->user(), $webDomain);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Web domain unsuspended.')]);

        return back();
    }

    /**
     * Delete the given web domain.
     */
    public function destroy(Request $request, WebDomain $webDomain): RedirectResponse
    {
        app(DeleteWebDomain::class)->handle($request->user(), $webDomain);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Web domain deleted.')]);

        return to_route('domains.index');
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
     * Shape a web domain for the frontend. No App\Http\Resources in this app; inline shaping
     * matches the only existing precedent (ProfileController).
     *
     * @return array<string, mixed>
     */
    private function present(WebDomain $webDomain): array
    {
        return [
            'uuid' => $webDomain->uuid,
            'domain' => $webDomain->domain,
            'aliases' => $webDomain->aliases->pluck('alias')->all(),
            'web_template' => $webDomain->web_template,
            'ssl_mode' => $webDomain->ssl_mode->value,
            'suspended_at' => $webDomain->suspended_at?->toIso8601String(),
            'suspension_source' => $webDomain->suspension_source?->value,
            'provisioning_status' => $webDomain->latestProvisioningOperation?->status->value,
        ];
    }
}
