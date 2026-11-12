<?php

namespace App\Http\Controllers\Settings;

use App\Actions\Users\ExportUserData;
use App\Actions\Users\ListUserDeletionBlockers;
use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\AuditEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $request->user()->fill($request->safe()->only(['name', 'email']));

        $emailChanged = $request->user()->isDirty('email');

        if ($emailChanged) {
            $request->user()->email_verified_at = null;
        }

        $request->user()->save();

        // The new address counts as the user's once they confirm it (see InviteMember).
        if ($emailChanged) {
            $request->user()->sendEmailVerificationNotification();
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Profile updated.')]);

        return to_route('profile.edit');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request, ListUserDeletionBlockers $blockers): RedirectResponse
    {
        $user = $request->user();

        $reasons = $blockers->handle($user);

        if ($reasons !== []) {
            return back()->withErrors(['deletion' => implode(' ', $reasons)]);
        }

        AuditEvent::record($user, 'user.deleted', $user);

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Download everything the panel holds about the signed-in user as JSON.
     */
    public function export(Request $request, ExportUserData $export): StreamedResponse
    {
        $data = $export->handle($request->user());

        return response()->streamDownload(
            fn () => print json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'my-data-'.now()->format('Ymd').'.json',
            ['Content-Type' => 'application/json'],
        );
    }
}
