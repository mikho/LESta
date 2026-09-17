<?php

namespace App\Http\Controllers\Memberships;

use App\Actions\Memberships\InviteMember;
use App\Actions\Memberships\RemoveMember;
use App\Http\Controllers\Controller;
use App\Http\Requests\Memberships\StoreMembershipRequest;
use App\Models\Account;
use App\Models\Membership;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MembershipController extends Controller
{
    /**
     * Invite a user to the given account, binding an existing user by email or creating a
     * brand-new one with a real password-reset link.
     */
    public function store(StoreMembershipRequest $request, Account $account): RedirectResponse
    {
        app(InviteMember::class)->handle(
            $request->user(),
            $account,
            $request->validated('name'),
            $request->validated('email'),
            $request->validated('role'),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member invited.')]);

        return to_route('accounts.show', $account);
    }

    /**
     * Remove the given membership from its account.
     */
    public function destroy(Request $request, Account $account, Membership $membership): RedirectResponse
    {
        app(RemoveMember::class)->handle($request->user(), $membership);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Member removed.')]);

        return to_route('accounts.show', $account);
    }
}
