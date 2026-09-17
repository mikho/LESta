<?php

namespace App\Actions\Memberships;

use App\Enums\RoleScope;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Invites a user to an account, binding an existing user by email or creating a brand-new one --
 * the exact same bind-or-create-with-reset-link pattern App\Actions\Accounts\CreateAccount's own
 * resolveOwner() already established, just applied to an arbitrary account role instead of always
 * "owner".
 */
class InviteMember
{
    public function handle(User $actor, Account $account, string $name, string $email, string $roleName): Membership
    {
        Gate::forUser($actor)->authorize('create', [Membership::class, $account]);

        if (! in_array($roleName, ['owner', 'member'], true)) {
            throw ValidationException::withMessages([
                'role' => 'Not a recognized account role.',
            ]);
        }

        return DB::transaction(function () use ($actor, $account, $name, $email, $roleName): Membership {
            $user = $this->resolveUser($name, $email);

            // One membership per user per account, regardless of role: memberships' own DB
            // constraint is only unique on (user_id, account_id, role_id), which would otherwise
            // allow the same user to hold two simultaneous roles (e.g. both owner and member) on
            // the same account -- a confusing state nothing in this app's own UI or authorization
            // logic is designed to handle, so it is refused here rather than silently allowed.
            if (Membership::where('user_id', $user->id)->where('account_id', $account->id)->exists()) {
                throw ValidationException::withMessages([
                    'email' => 'This user is already a member of this account.',
                ]);
            }

            $role = Role::query()->firstOrCreate(['name' => $roleName], ['scope' => RoleScope::Account]);

            $membership = Membership::create([
                'user_id' => $user->id,
                'account_id' => $account->id,
                'role_id' => $role->id,
            ]);

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $membership->getMorphClass(),
                'auditable_id' => $membership->getKey(),
                'action' => 'membership.invited',
                'correlation_id' => (string) Str::uuid(),
            ]);

            return $membership;
        });
    }

    /**
     * Binds an existing user by email, or creates a brand-new one with a random, unusable
     * password and immediately sends them a real password-reset link. Identical to
     * CreateAccount::resolveOwner() -- duplicated rather than shared, matching this project's own
     * established "concrete shared lib files only for real cross-cutting infrastructure" scope
     * (see .install/services/backups/install.sh's own top comment for the same stated
     * convention in the shell installers).
     */
    private function resolveUser(string $name, string $email): User
    {
        $user = User::where('email', $email)->first();

        if ($user !== null) {
            return $user;
        }

        $user = User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(Str::random(40)),
        ]);

        $user->forceFill(['email_verified_at' => now()])->save();

        Password::sendResetLink(['email' => $user->email]);

        return $user;
    }
}
