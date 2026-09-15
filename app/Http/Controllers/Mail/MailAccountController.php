<?php

namespace App\Http\Controllers\Mail;

use App\Actions\Mail\CreateMailAccount;
use App\Actions\Mail\DeleteMailAccount;
use App\Actions\Mail\RotateMailAccountPassword;
use App\Actions\Mail\SuspendMailAccount;
use App\Actions\Mail\UnsuspendMailAccount;
use App\Actions\Mail\UpdateMailAccount;
use App\Exceptions\ResourceQuotaExceededException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Mail\StoreMailAccountRequest;
use App\Http\Requests\Mail\UpdateMailAccountRequest;
use App\Models\MailAccount;
use App\Models\MailDomain;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class MailAccountController extends Controller
{
    /**
     * Store a newly created mail account on the given domain. The generated password is
     * one-time-flashed back, never included in any page prop: MailAccount::password is
     * `encrypted`-cast and never re-readable after this.
     */
    public function store(StoreMailAccountRequest $request, MailDomain $mailDomain): RedirectResponse
    {
        try {
            [, $password] = app(CreateMailAccount::class)->handle($request->user(), $mailDomain, $request->validated());
        } catch (ResourceQuotaExceededException $e) {
            throw ValidationException::withMessages(['local_part' => $e->getMessage()]);
        }

        Inertia::flash('mailAccountPassword', $password);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mail account added.')]);

        return to_route('mail.edit', $mailDomain);
    }

    /**
     * Update the given mail account.
     */
    public function update(UpdateMailAccountRequest $request, MailDomain $mailDomain, MailAccount $mailAccount): RedirectResponse
    {
        abort_unless($mailAccount->mail_domain_id === $mailDomain->id, 404);

        app(UpdateMailAccount::class)->handle($request->user(), $mailAccount, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mail account updated.')]);

        return to_route('mail.edit', $mailDomain);
    }

    /**
     * Delete the given mail account.
     */
    public function destroy(Request $request, MailDomain $mailDomain, MailAccount $mailAccount): RedirectResponse
    {
        abort_unless($mailAccount->mail_domain_id === $mailDomain->id, 404);

        app(DeleteMailAccount::class)->handle($request->user(), $mailAccount);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mail account deleted.')]);

        return to_route('mail.edit', $mailDomain);
    }

    /**
     * Suspend the given mail account.
     */
    public function suspend(Request $request, MailDomain $mailDomain, MailAccount $mailAccount): RedirectResponse
    {
        abort_unless($mailAccount->mail_domain_id === $mailDomain->id, 404);

        app(SuspendMailAccount::class)->handle($request->user(), $mailAccount);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mail account suspended.')]);

        return back();
    }

    /**
     * Unsuspend the given mail account.
     */
    public function unsuspend(Request $request, MailDomain $mailDomain, MailAccount $mailAccount): RedirectResponse
    {
        abort_unless($mailAccount->mail_domain_id === $mailDomain->id, 404);

        app(UnsuspendMailAccount::class)->handle($request->user(), $mailAccount);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mail account unsuspended.')]);

        return back();
    }

    /**
     * Rotate the given mail account's password. The new plaintext is one-time-flashed back, the
     * same mechanism as store().
     */
    public function rotatePassword(Request $request, MailDomain $mailDomain, MailAccount $mailAccount): RedirectResponse
    {
        abort_unless($mailAccount->mail_domain_id === $mailDomain->id, 404);

        [, $password] = app(RotateMailAccountPassword::class)->handle($request->user(), $mailAccount);

        Inertia::flash('mailAccountPassword', $password);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Mail account password rotated.')]);

        return to_route('mail.edit', $mailDomain);
    }
}
