<?php

namespace App\Http\Controllers\Support;

use App\Actions\Support\StartImpersonation;
use App\Actions\Support\StopImpersonation;
use App\Http\Controllers\Controller;
use App\Http\Requests\Support\StartImpersonationRequest;
use App\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ImpersonationController extends Controller
{
    /**
     * Start impersonating the user behind the given membership: a real session swap
     * (App\Actions\Support\StartImpersonation already does this, audited, permission-gated), not
     * the separate read-only support view. Lands on the dashboard -- the same page the
     * impersonated user would see logging in themselves.
     */
    public function store(StartImpersonationRequest $request, Membership $membership): RedirectResponse
    {
        app(StartImpersonation::class)->handle($request->user(), $membership, $request->validated('reason'));

        return to_route('dashboard');
    }

    /**
     * Stop impersonating and return to the admin's own session.
     */
    public function destroy(Request $request): RedirectResponse
    {
        app(StopImpersonation::class)->handle($request);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Returned to your own account.')]);

        return to_route('accounts.index');
    }
}
