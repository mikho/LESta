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

        session([
            'impersonator_id' => $admin->id,
            'impersonated_membership_id' => $membership->id,
        ]);
        Auth::loginUsingId($membership->user_id);
        request()->session()->regenerate();
    }
}
