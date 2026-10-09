<?php

namespace App\Listeners;

use App\Models\User;
use Illuminate\Auth\Events\Login;

/**
 * Remembers when and from where a user signed in, keeping the time before as well, so the dashboard
 * can show "Last sign-in" as the previous one (the current one is the visit being made). Never
 * stored for an impersonated session: that is not the user signing in.
 */
class RecordSignIn
{
    public function handle(Login $event): void
    {
        $user = $event->user;

        if (! $user instanceof User || session()->has('impersonator_id')) {
            return;
        }

        $user->forceFill([
            'previous_login_at' => $user->last_login_at,
            'previous_login_ip' => $user->last_login_ip,
            'last_login_at' => now(),
            'last_login_ip' => request()->ip(),
        ])->saveQuietly();
    }
}
