<?php

namespace App\Http\Controllers\Domains;

use App\Actions\AccountNodeIdentities\UpdateSshPublicKey;
use App\Actions\Domains\CreateWebDomain;
use App\Actions\Domains\DeleteWebDomain;
use App\Actions\Domains\SuspendWebDomain;
use App\Actions\Domains\UnsuspendWebDomain;
use App\Actions\Domains\UpdateWebDomain;
use App\Actions\Provisioning\EnsuresAccountNodeIdentity;
use App\Concerns\ListsCustomerResources;
use App\Concerns\ResolvesCurrentAccount;
use App\Exceptions\ResourceQuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Domains\StoreWebDomainRequest;
use App\Http\Requests\Domains\UpdateSshPublicKeyRequest;
use App\Http\Requests\Domains\UpdateWebDomainRequest;
use App\Models\WebDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WebDomainController extends Controller
{
    use ListsCustomerResources;
    use ResolvesCurrentAccount;

    /**
     * Show the account's web domain list.
     */
    public function index(Request $request): Response
    {
        if ($request->user()->can('viewAnyAcrossAccounts', WebDomain::class)) {
            return Inertia::render('domains/index', [
                'webDomains' => null,
                'search' => '',
                'customers' => $this->customerListing(
                    $request,
                    WebDomain::query()->with(['aliases', 'latestProvisioningOperation']),
                    fn (WebDomain $item): array => $this->present($item),
                    'domain',
                    ['domain' => 'web_domains.domain'],
                ),
            ]);
        }

        $account = $this->resolveAccount($request->user());

        if ($account === null) {
            return Inertia::render('domains/index', ['webDomains' => null, 'search' => '']);
        }

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
     * Show the form for creating a new web domain. No account -> nothing sensible to render;
     * back to the index, which shows the same real "no hosting account" notice.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $account = $this->resolveAccount($request->user());

        if ($account === null) {
            return to_route('domains.index');
        }

        Gate::authorize('create', [WebDomain::class, $account]);

        return Inertia::render('domains/create');
    }

    /**
     * Store a newly created web domain.
     */
    public function store(StoreWebDomainRequest $request): RedirectResponse
    {
        $account = $this->resolveAccount($request->user());

        if ($account === null) {
            return to_route('domains.index');
        }

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

        $webDomain->load(['aliases', 'latestProvisioningOperation', 'node']);

        // Backfills the identity for a web domain created before this feature existed -- safe
        // and idempotent to call on every edit view, matching CreateWebDomain's own call site.
        $identity = app(EnsuresAccountNodeIdentity::class)->handle($webDomain->account, $webDomain->node);

        return Inertia::render('domains/edit', [
            'webDomain' => $this->present($webDomain),
            'sftp' => [
                'identityUuid' => $identity->uuid,
                'username' => $identity->system_username,
                'hasSshPublicKey' => $identity->ssh_public_key !== null,
            ],
        ]);
    }

    /**
     * Set or clear the tenant's own SFTP login key for this web domain's node.
     */
    public function updateSshKey(UpdateSshPublicKeyRequest $request, WebDomain $webDomain): RedirectResponse
    {
        // Backfills the identity exactly like edit() above -- a client may reasonably submit
        // this route without ever having loaded the edit page first (a saved bookmark, a
        // scripted client), and the identity is otherwise guaranteed to exist by the time any
        // web domain does, per CreateWebDomain's own call site.
        $identity = app(EnsuresAccountNodeIdentity::class)->handle($webDomain->account, $webDomain->node);

        app(UpdateSshPublicKey::class)->handle($request->user(), $identity, $request->validated('ssh_public_key'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('SFTP key updated.')]);

        return to_route('domains.edit', $webDomain);
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
            'web_server' => $webDomain->web_server->value,
            'php_version' => $webDomain->php_version?->value,
            'ssl_mode' => $webDomain->ssl_mode->value,
            'waf_mode' => $webDomain->waf_mode,
            'waf_preset' => $webDomain->waf_preset,
            'hotlink_protection' => $webDomain->hotlink_protection,
            'hotlink_allowed_hosts' => ($webDomain->hotlink_allowed_hosts ?? []),
            'waf_excluded_rules' => ($webDomain->waf_excluded_rules ?? []),
            'certificate_issued_at' => $webDomain->certificate_issued_at?->toIso8601String(),
            'certificate_expires_at' => $webDomain->certificate_expires_at?->toIso8601String(),
            'last_certificate_error' => $webDomain->last_certificate_error,
            'suspended_at' => $webDomain->suspended_at?->toIso8601String(),
            'suspension_source' => $webDomain->suspension_source?->value,
            'provisioning_status' => $webDomain->latestProvisioningOperation?->status->value,
        ];
    }
}
