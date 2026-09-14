<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * @return HasMany<Membership, $this>
     */
    public function memberships(): HasMany
    {
        return $this->hasMany(Membership::class);
    }

    /**
     * @return HasMany<NodeAdminGrant, $this>
     */
    public function nodeAdminGrants(): HasMany
    {
        return $this->hasMany(NodeAdminGrant::class);
    }

    public function isProviderAdmin(): bool
    {
        return $this->memberships()->whereNull('account_id')
            ->whereHas('role', fn ($query) => $query->where('name', 'provider_admin'))->exists();
    }

    /**
     * True for a direct membership on $account with role $roleName, or (see Account::
     * resellerAccount's own doc comment) the exact same role on the reseller account that
     * manages $account, if any -- a reseller-account owner is indistinguishable from a direct
     * owner everywhere this method is already the authorization boundary, with zero changes
     * needed to any individual resource policy. Bounded to one real hop: AssignAccountToReseller
     * refuses to make a reseller account itself reseller-managed, so this can never loop.
     */
    public function hasAccountRole(Account $account, string $roleName): bool
    {
        if ($this->memberships()->where('account_id', $account->id)
            ->whereHas('role', fn ($query) => $query->where('name', $roleName))->exists()) {
            return true;
        }

        if ($account->reseller_account_id === null) {
            return false;
        }

        return $this->hasAccountRole($account->resellerAccount, $roleName);
    }

    /**
     * True for any direct membership on $account (any role), or the same reseller-account
     * fallback as hasAccountRole() above. This exists because view/viewAny abilities across this
     * app's own resource policies check membership existence directly rather than going through
     * hasAccountRole() (there is no single "any role" role name to check) -- see e.g.
     * WebDomainPolicy::viewAny.
     */
    public function hasAnyAccountMembership(Account $account): bool
    {
        if ($this->memberships()->where('account_id', $account->id)->exists()) {
            return true;
        }

        if ($account->reseller_account_id === null) {
            return false;
        }

        return $this->hasAnyAccountMembership($account->resellerAccount);
    }

    /**
     * Whether this user's platform-scope membership's role carries $name (a
     * Permission::CATALOG entry), via the real permission_role grant --
     * never a role-name check. Mirrors isProviderAdmin()'s own
     * whereNull('account_id') scoping: a permission is always a platform-
     * wide grant, never derived from any one account-scoped membership.
     */
    public function hasPermission(string $name): bool
    {
        return $this->memberships()->whereNull('account_id')
            ->whereHas('role.permissions', fn ($query) => $query->where('name', $name))->exists();
    }

    /**
     * Whether this user has been delegated full admin of $node specifically (see
     * App\Models\NodeAdminGrant's own doc comment): infrastructure-only, never a path to any
     * tenant resource hosted on that node. A full provider_admin always passes too, so callers
     * never need to check isProviderAdmin() separately alongside this.
     */
    public function hasNodeAdminGrant(Node $node): bool
    {
        return $this->isProviderAdmin()
            || $this->nodeAdminGrants()->where('node_id', $node->id)->exists();
    }
}
