<?php

namespace App\Actions\Roles;

use App\Enums\RoleScope;
use App\Models\AuditEvent;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * Creates a custom PLATFORM-scope role. Always hardcodes scope to Platform -- this action, and
 * the Roles admin UI it backs, never creates or touches an account-scope role (owner/member are
 * fixed, structural rows created directly by RoleSeeder/CreateAccount, never through here).
 */
class CreateRole
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{name: string, description: string|null, permissions: list<string>}
     */
    public function handle(User $actor, array $data): Role
    {
        Gate::forUser($actor)->authorize('create', Role::class);

        return DB::transaction(function () use ($actor, $data): Role {
            $role = Role::create([
                'name' => $data['name'],
                'scope' => RoleScope::Platform,
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
                'action' => 'role.created',
                'correlation_id' => (string) Str::uuid(),
            ]);

            return $role;
        });
    }
}
