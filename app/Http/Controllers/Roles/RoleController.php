<?php

namespace App\Http\Controllers\Roles;

use App\Actions\Roles\CreateRole;
use App\Actions\Roles\DeleteRole;
use App\Actions\Roles\UpdateRole;
use App\Enums\RoleScope;
use App\Http\Controllers\Controller;
use App\Http\Requests\Roles\StoreRoleRequest;
use App\Http\Requests\Roles\UpdateRoleRequest;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RoleController extends Controller
{
    /**
     * Show the platform-wide custom-role list. Only Platform-scope rows: owner/member are fixed,
     * structural account-scope roles this admin UI never manages.
     */
    public function index(): Response
    {
        Gate::authorize('viewAny', Role::class);

        $roles = Role::query()
            ->where('scope', RoleScope::Platform)
            // provider_admin is the fixed foundational role (RoleSeeder), never editable or
            // deletable through this UI (see UpdateRole/DeleteRole's own guards) -- left out of
            // the list entirely rather than shown with every action disabled.
            ->where('name', '!=', 'provider_admin')
            ->withCount('memberships')
            ->orderBy('name')
            ->get();

        return Inertia::render('roles/index', [
            'roles' => $roles->map(fn (Role $role): array => $this->presentForIndex($role))->all(),
        ]);
    }

    /**
     * Show the form for creating a new custom role.
     */
    public function create(): Response
    {
        Gate::authorize('create', Role::class);

        return Inertia::render('roles/create', [
            'permissionCatalog' => Permission::CATALOG,
        ]);
    }

    /**
     * Store a newly created role.
     */
    public function store(StoreRoleRequest $request): RedirectResponse
    {
        $role = app(CreateRole::class)->handle($request->user(), $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role created.')]);

        return to_route('roles.edit', $role);
    }

    /**
     * Show the form for editing a role's name, description, and permissions.
     */
    public function edit(Role $role): Response
    {
        Gate::authorize('update', $role);

        abort_unless($role->scope === RoleScope::Platform && $role->name !== 'provider_admin', 404);

        $role->load('permissions');

        return Inertia::render('roles/edit', [
            'role' => $this->presentForEdit($role),
            'permissionCatalog' => Permission::CATALOG,
        ]);
    }

    /**
     * Update the given role.
     */
    public function update(UpdateRoleRequest $request, Role $role): RedirectResponse
    {
        app(UpdateRole::class)->handle($request->user(), $role, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role updated.')]);

        return to_route('roles.edit', $role);
    }

    /**
     * Delete the given role.
     */
    public function destroy(Request $request, Role $role): RedirectResponse
    {
        app(DeleteRole::class)->handle($request->user(), $role);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Role deleted.')]);

        return to_route('roles.index');
    }

    /**
     * Shape a role for the index listing. No App\Http\Resources in this app; inline shaping
     * matches the existing precedent (PackageController).
     *
     * @return array<string, mixed>
     */
    private function presentForIndex(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'memberships_count' => $role->memberships_count,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentForEdit(Role $role): array
    {
        return [
            'id' => $role->id,
            'name' => $role->name,
            'description' => $role->description,
            'permissions' => $role->permissions->pluck('name')->all(),
        ];
    }
}
