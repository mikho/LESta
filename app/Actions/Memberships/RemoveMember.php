<?php

namespace App\Actions\Memberships;

use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class RemoveMember
{
    public function handle(User $actor, Membership $membership): void
    {
        Gate::forUser($actor)->authorize('delete', $membership);

        if ($membership->account_id === null) {
            throw ValidationException::withMessages([
                'membership' => 'A platform-scope membership cannot be removed here.',
            ]);
        }

        DB::transaction(function () use ($actor, $membership): void {
            if ($membership->role->name === 'owner') {
                $remainingOwners = Membership::query()
                    ->where('account_id', $membership->account_id)
                    ->where('id', '!=', $membership->id)
                    ->whereHas('role', fn ($query) => $query->where('name', 'owner'))
                    ->count();

                if ($remainingOwners === 0) {
                    throw ValidationException::withMessages([
                        'membership' => 'An account must always have at least one owner.',
                    ]);
                }
            }

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $membership->getMorphClass(),
                'auditable_id' => $membership->getKey(),
                'action' => 'membership.removed',
                'correlation_id' => (string) Str::uuid(),
            ]);

            $membership->delete();
        });
    }
}
