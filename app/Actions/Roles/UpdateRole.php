<?php

namespace App\Actions\Roles;

use App\Models\AuditEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class UpdateRole
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{name: string, description: string|null, permissions: list<string>}
     */
    public function handle(User $actor, Role $role, array $data): void
    {
        Gate::forUser($actor)->authorize('update', $role);

        // provider_admin is the one role every real ProviderAdminManaged bypass and every
        // existing test in this app assumes always holds the full permission catalog -- editing
        // it here (renaming it, or narrowing its permissions) could silently strand every
        // provider admin's own access. It is not exposed as an editable row in the Roles admin
        // UI at all, but this guard stays here too as the actual, unbypassable boundary.
        if ($role->name === 'provider_admin') {
            throw ValidationException::withMessages([
                'role' => 'The provider_admin role cannot be edited.',
            ]);
        }

        DB::transaction(function () use ($actor, $role, $data): void {
            $role->update([
                'name' => $data['name'],
                'description' => $data['description'] ?? null,
            ]);

            $permissionIds = Permission::query()
                ->whereIn('name', $data['permissions'] ?? [])
                ->pluck('id');

            $role->permissions()->sync($permissionIds);

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $role->getMorphClass(),
                'auditable_id' => $role->getKey(),
                'action' => 'role.updated',
                'correlation_id' => (string) Str::uuid(),
            ]);
        });
    }
}
