<?php

namespace App\Actions\Support;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class StartImpersonation
{
    public function handle(User $admin, Membership $membership, string $reason): void
    {
        Gate::forUser($admin)->authorize('impersonate', $membership);

        AuditEvent::create([
            'actor_type' => $admin->getMorphClass(),
            'actor_id' => $admin->getKey(),
            'auditable_type' => $membership->getMorphClass(),
            'auditable_id' => $membership->getKey(),
            'action' => 'impersonation.started',
            'correlation_id' => (string) Str::uuid(),
            'metadata' => [
                'reason' => $reason,
                'admin_id' => $admin->id,
                'membership_id' => $membership->id,
                'target_user_id' => $membership->user_id,
            ],
        ]);

        // The admin's own password confirmation must not carry over: it is what the customer's
        // security settings would otherwise accept as the customer's (LimitsImpersonation refuses
        // those pages too, and ends the impersonation after a while).
        session()->forget('auth.password_confirmed_at');
        session([
            'impersonator_id' => $admin->id,
            'impersonated_membership_id' => $membership->id,
            'impersonation_started_at' => now()->getTimestamp(),
        ]);
        Auth::loginUsingId($membership->user_id);
        request()->session()->regenerate();
    }
}
