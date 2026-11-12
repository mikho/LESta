<?php

namespace App\Actions\Roles;

use App\Enums\RoleScope;
use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class DeleteRole
{
    public function handle(User $actor, Role $role): void
    {
        Gate::forUser($actor)->authorize('delete', $role);

        if ($role->name === 'provider_admin') {
            throw ValidationException::withMessages([
                'role' => 'The provider_admin role cannot be deleted.',
            ]);
        }

        // Only the provider's own (platform) roles are managed here. The account roles (owner,
        // member and the like) are fixed: every account policy depends on their names.
        if ($role->scope !== RoleScope::Platform) {
            throw ValidationException::withMessages([
                'role' => 'Only platform roles can be deleted.',
            ]);
        }

        // memberships.role_id is restrictOnDelete, so this would otherwise surface as a raw,
        // uncaught QueryException -- the same class of gap DeleteAccount's own tenantDatabases/
        // cronJobs check was added to close.
        if ($role->memberships()->exists()) {
            throw ValidationException::withMessages([
                'role' => 'This role is still assigned to at least one user and cannot be deleted.',
            ]);
        }

        DB::transaction(function () use ($actor, $role): void {
            AuditEvent::record($role, 'role.deleted', $actor);

            $role->delete();
        });
    }
}
