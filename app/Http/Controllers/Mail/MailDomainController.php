<?php

namespace App\Http\Controllers\Mail;

use App\Actions\Mail\CreateMailDomain;
use App\Actions\Mail\DeleteMailDomain;
use App\Actions\Mail\SuspendMailDomain;
use App\Actions\Mail\UnsuspendMailDomain;
use App\Actions\Mail\UpdateMailDomain;
use App\Concerns\ResolvesCurrentAccount;
use App\Exceptions\ResourceQuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\StoreMailDomainRequest;
use App\Http\Requests\Mail\UpdateMailDomainRequest;
use App\Models\MailAccount;
use App\Models\MailDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class MailDomainController extends Controller
{
    use ResolvesCurrentAccount;

    /**
     * Show the account's mail domain list.
     */
    public function index(Request $request): Response
    {
        $account = $this->resolveAccount($request->user());

        if ($account === null) {
            return Inertia::render('mail/index', ['mailDomains' => null, 'search' => '']);
        }

        Gate::authorize('viewAny', [MailDomain::class, $account]);

        $search = trim((string) $request->string('search'));

        $mailDomains = $account->mailDomains()
            ->withCount('accounts')
            ->with('latestProvisioningOperation')
            ->when($search !== '', fn ($query) => $query->where('domain', 'like', '%'.$search.'%'))
            ->orderBy('domain')
            ->paginate(15)
            ->withQueryString();

        $mailDomains->through(fn (MailDomain $mailDomain): array => $this->presentForIndex($mailDomain));

        return Inertia::render('mail/index', [
            'mailDomains' => $mailDomains,
            'search' => $search,
        ]);
    }

    /**
     * Show the form for creating a new mail domain. No account -> nothing sensible to render;
     * back to the index, which shows the same real "no hosting account" notice.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        $account = $this->resolveAccount($request->user());

        if ($account === null) {
            return to_route('mail.index');
        }

        Gate::authorize('create', [MailDomain::class, $account]);

        return Inertia::render('mail/create');
    }

    /**
     * Store a newly created mail domain.
     */
    public function store(StoreMailDomainRequest $request): RedirectResponse
    {
        $account = $this->resolveAccount($request->user());

        if ($account === null) {
            return to_route('mail.index');
        }

        try {
            app(CreateMailDomain::class)->handle($request->user(), $account, $request->validated());
        } catch (ResourceQuotaExceededException $e) {
            throw ValidationException::withMessages(['domain' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mail domain created.')]);

        return to_route('mail.index');
    }

    /**
     * Show the form for editing a mail domain.
     */
    public function edit(Request $request, MailDomain $mailDomain): Response
    {
        Gate::authorize('update', $mailDomain);

        $mailDomain->load(['accounts', 'latestProvisioningOperation']);

        return Inertia::render('mail/edit', [
            'mailDomain' => $this->presentForEdit($mailDomain),
        ]);
    }

    /**
     * Update the given mail domain.
     */
    public function update(UpdateMailDomainRequest $request, MailDomain $mailDomain): RedirectResponse
    {
        app(UpdateMailDomain::class)->handle($request->user(), $mailDomain, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mail domain updated.')]);

        return to_route('mail.edit', $mailDomain);
    }

    /**
     * Suspend the given mail domain.
     */
    public function suspend(Request $request, MailDomain $mailDomain): RedirectResponse
    {
        app(SuspendMailDomain::class)->handle($request->user(), $mailDomain);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mail domain suspended.')]);

        return back();
    }

    /**
     * Unsuspend the given mail domain.
     */
    public function unsuspend(Request $request, MailDomain $mailDomain): RedirectResponse
    {
        app(UnsuspendMailDomain::class)->handle($request->user(), $mailDomain);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mail domain unsuspended.')]);

        return back();
    }

    /**
     * Delete the given mail domain.
     */
    public function destroy(Request $request, MailDomain $mailDomain): RedirectResponse
    {
        app(DeleteMailDomain::class)->handle($request->user(), $mailDomain);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mail domain deleted.')]);

        return to_route('mail.index');
    }

    /**
     * Shape a mail domain for the index listing. No App\Http\Resources in this app; inline
     * shaping matches the existing precedent (DnsZoneController).
     *
     * @return array<string, mixed>
     */
    private function presentForIndex(MailDomain $mailDomain): array
    {
        return [
            'uuid' => $mailDomain->uuid,
            'domain' => $mailDomain->domain,
            'antivirus_enabled' => $mailDomain->antivirus_enabled,
            'antispam_enabled' => $mailDomain->antispam_enabled,
            'dkim_enabled' => $mailDomain->dkim_enabled,
            'suspended_at' => $mailDomain->suspended_at?->toIso8601String(),
            'suspension_source' => $mailDomain->suspension_source?->value,
            'provisioning_status' => $mailDomain->latestProvisioningOperation?->status->value,
            'accounts_count' => $mailDomain->accounts_count,
        ];
    }

    /**
     * Shape a mail domain, with its accounts, for the edit page.
     *
     * @return array<string, mixed>
     */
    private function presentForEdit(MailDomain $mailDomain): array
    {
        return [
            'uuid' => $mailDomain->uuid,
            'domain' => $mailDomain->domain,
            'antivirus_enabled' => $mailDomain->antivirus_enabled,
            'antispam_enabled' => $mailDomain->antispam_enabled,
            'dkim_enabled' => $mailDomain->dkim_enabled,
            'dkim_selector' => $mailDomain->dkim_selector,
            'dkim_selector_activated_at' => $mailDomain->dkim_selector_activated_at?->toIso8601String(),
            'catchall_email' => $mailDomain->catchall_email,
            'suspended_at' => $mailDomain->suspended_at?->toIso8601String(),
            'suspension_source' => $mailDomain->suspension_source?->value,
            'provisioning_status' => $mailDomain->latestProvisioningOperation?->status->value,
            'accounts' => $mailDomain->accounts->map(fn (MailAccount $account): array => $this->presentAccount($account))->all(),
        ];
    }

    /**
     * Shape a mail account for the frontend. Never includes the password: MailAccount::password
     * is only ever handed back once, from store()/rotatePassword(), via a dedicated flash key.
     *
     * @return array<string, mixed>
     */
    private function presentAccount(MailAccount $account): array
    {
        return [
            'uuid' => $account->uuid,
            'local_part' => $account->local_part,
            'quota_mb' => $account->quota_mb,
            'forward_to' => $account->forward_to,
            'forward_only' => $account->forward_only,
            'autoreply_enabled' => $account->autoreply_enabled,
            'autoreply_message' => $account->autoreply_message,
            'suspended_at' => $account->suspended_at?->toIso8601String(),
            'suspension_source' => $account->suspension_source?->value,
        ];
    }
}
