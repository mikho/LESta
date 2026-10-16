<?php

namespace App\Policies;

use App\Models\ApiToken;
use App\Models\User;

class ApiTokenPolicy
{
    /**
     * Account owners (a reseller-account owner included) and platform users may have API tokens;
     * other account members may not (owner's decision, see the vault's "Tenant and Admin API Design").
     */
    public function create(User $user): bool
    {
        return $user->isPlatformUser() || $user->ownsAnyAccount();
    }

    public function viewAny(User $user): bool
    {
        return $this->create($user);
    }

    /**
     * Only the token's own user revokes it. Revoking stays possible after the user lost the right to
     * create one, so a former owner can still clean up.
     */
    public function delete(User $user, ApiToken $token): bool
    {
        return $token->user_id === $user->id;
    }
}
