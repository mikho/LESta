<?php

namespace App\Actions\Roles;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
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

        // memberships.role_id is restrictOnDelete, so this would otherwise surface as a raw,
        // uncaught QueryException -- the same class of gap DeleteAccount's own tenantDatabases/
        // cronJobs check was added to close.
        if ($role->memberships()->exists()) {
            throw ValidationException::withMessages([
                'role' => 'This role is still assigned to at least one user and cannot be deleted.',
            ]);
        }

        DB::transaction(function () use ($actor, $role): void {
            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $role->getMorphClass(),
                'auditable_id' => $role->getKey(),
                'action' => 'role.deleted',
                'correlation_id' => (string) Str::uuid(),
            ]);

            $role->delete();
        });
    }
}
