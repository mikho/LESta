<?php

namespace App\Actions\Accounts;

use App\Enums\RoleScope;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

/**
 * Creates a hosting account, binding an existing or brand-new user as its owner. Platform-admin-
 * only (checked directly against accounts.create, mirroring GrantNodeAdmin's own shape) --
 * reseller-initiated creation is a deliberately deferred, separate capability for a later phase.
 */
class CreateAccount
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{name: string, contact_email: string|null, package_id: int, owner_name: string, owner_email: string}
     */
    public function handle(User $actor, array $data): Account
    {
        if (! $actor->hasPermission('accounts.create')) {
            throw new AuthorizationException;
        }

        return DB::transaction(function () use ($actor, $data): Account {
            $owner = $this->resolveOwner($data['owner_name'], $data['owner_email']);

            $account = Account::create([
                'name' => $data['name'],
                'contact_email' => $data['contact_email'] ?? $owner->email,
                'package_id' => $data['package_id'],
            ]);

            $ownerRole = Role::query()->firstOrCreate(['name' => 'owner'], ['scope' => RoleScope::Account]);

            Membership::create([
                'user_id' => $owner->id,
                'account_id' => $account->id,
                'role_id' => $ownerRole->id,
            ]);

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $account->getMorphClass(),
                'auditable_id' => $account->getKey(),
                'action' => 'account.created',
                'correlation_id' => (string) Str::uuid(),
            ]);

            return $account;
        });
    }

    /**
     * Binds an existing user by email, or creates a brand-new one with a random, unusable
     * password and immediately sends them a real password-reset link (reusing Fortify's own
     * already-configured, already-working reset pipeline) so they can set their own password.
     * email_verified_at is set immediately: the admin is vouching for this address, and the user
     * can do nothing with the account until they complete that reset flow, which itself proves
     * mailbox ownership.
     */
    private function resolveOwner(string $ownerName, string $ownerEmail): User
    {
        $owner = User::where('email', $ownerEmail)->first();

        if ($owner !== null) {
            return $owner;
        }

        $owner = User::create([
            'name' => $ownerName,
            'email' => $ownerEmail,
            'password' => Hash::make(Str::random(40)),
        ]);

        // email_verified_at is deliberately not mass-assignable (User's own Fillable list), so
        // this needs its own explicit write.
        $owner->forceFill(['email_verified_at' => now()])->save();

        Password::sendResetLink(['email' => $owner->email]);

        return $owner;
    }
}
