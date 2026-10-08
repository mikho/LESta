<?php

namespace App\Http\Controllers\Domains;

use App\Actions\Domains\RerenderWebDomain;
use App\Http\Controllers\Controller;
use App\Http\Requests\Domains\StoreWebDomainRedirectRequest;
use App\Models\AuditEvent;
use App\Models\WebDomain;
use App\Models\WebDomainRedirect;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * A web domain's URL redirects. Every change re-renders that domain's nginx vhost.
 */
class WebDomainRedirectController extends Controller
{
    public function store(StoreWebDomainRedirectRequest $request, WebDomain $webDomain): RedirectResponse
    {
        Gate::authorize('update', $webDomain);

        $data = $request->validated();

        if ($webDomain->redirects()->count() >= WebDomainRedirect::MAX_PER_DOMAIN) {
            throw ValidationException::withMessages(['source' => __('A domain can have at most :limit redirects.', ['limit' => WebDomainRedirect::MAX_PER_DOMAIN])]);
        }

        if ($webDomain->redirects()->where('source', $data['source'])->exists()) {
            throw ValidationException::withMessages(['source' => __('That path already has a redirect.')]);
        }

        $redirect = $webDomain->redirects()->create($data);

        $this->audit($request, $redirect, 'web_domain.redirect_created');
        app(RerenderWebDomain::class)->handle($webDomain);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Redirect added.')]);

        return to_route('domains.edit', $webDomain);
    }

    public function destroy(Request $request, WebDomain $webDomain, WebDomainRedirect $redirect): RedirectResponse
    {
        Gate::authorize('update', $webDomain);

        abort_unless($redirect->web_domain_id === $webDomain->id, 404);

        $this->audit($request, $redirect, 'web_domain.redirect_deleted');
        $redirect->delete();
        app(RerenderWebDomain::class)->handle($webDomain);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Redirect removed.')]);

        return to_route('domains.edit', $webDomain);
    }

    private function audit(Request $request, WebDomainRedirect $redirect, string $action): void
    {
        AuditEvent::create([
            'actor_type' => $request->user()->getMorphClass(),
            'actor_id' => $request->user()->getKey(),
            'auditable_type' => $redirect->getMorphClass(),
            'auditable_id' => $redirect->getKey(),
            'action' => $action,
            'correlation_id' => (string) Str::uuid(),
        ]);
    }
}
