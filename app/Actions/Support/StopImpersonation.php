<?php

namespace App\Actions\Support;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

class StopImpersonation
{
    public function handle(Request $request): void
    {
        $impersonatorId = $request->session()->get('impersonator_id');

        if ($impersonatorId === null) {
            return;
        }

        $membershipId = $request->session()->get('impersonated_membership_id');
        $admin = User::query()->findOrFail((int) $impersonatorId);
        $membership = $membershipId !== null ? Membership::query()->find((int) $membershipId) : null;

        AuditEvent::create([
            'actor_type' => $admin->getMorphClass(),
            'actor_id' => $admin->getKey(),
            'auditable_type' => Membership::class,
            'auditable_id' => $membership?->getKey() ?? (int) $membershipId,
            'action' => 'impersonation.ended',
            'correlation_id' => (string) Str::uuid(),
            'metadata' => ['membership_id' => $membershipId],
        ]);

        $request->session()->forget(['impersonator_id', 'impersonated_membership_id']);
        Auth::loginUsingId($admin->id);
        $request->session()->regenerate();
    }
}
