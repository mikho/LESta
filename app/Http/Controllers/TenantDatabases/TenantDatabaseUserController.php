<?php

namespace App\Http\Controllers\TenantDatabases;

use App\Actions\TenantDatabases\CreateTenantDatabaseUser;
use App\Actions\TenantDatabases\DeleteTenantDatabaseUser;
use App\Actions\TenantDatabases\RotateTenantDatabaseUserPassword;
use App\Actions\TenantDatabases\UpdateTenantDatabaseUser;
use App\Http\Controllers\Controller;
use App\Http\Requests\TenantDatabases\StoreTenantDatabaseUserRequest;
use App\Http\Requests\TenantDatabases\UpdateTenantDatabaseUserRequest;
use App\Models\TenantDatabase;
use App\Models\TenantDatabaseUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * A tenant database's extra users, managed on the database's edit page. A
 * new or rotated password reaches the customer once, through the same
 * 'generatedPassword' flash the database's own password uses.
 */
class TenantDatabaseUserController extends Controller
{
    public function store(StoreTenantDatabaseUserRequest $request, TenantDatabase $tenantDatabase): RedirectResponse
    {
        /** @var array{label: string, access: string} $data */
        $data = $request->validated();
        [$databaseUser, $password] = app(CreateTenantDatabaseUser::class)->handle($request->user(), $tenantDatabase, $data);

        $this->flashPassword($tenantDatabase, $databaseUser, $password);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Database user created.')]);

        return to_route('tenant-databases.edit', $tenantDatabase);
    }

    public function update(UpdateTenantDatabaseUserRequest $request, TenantDatabase $tenantDatabase, TenantDatabaseUser $user): RedirectResponse
    {
        /** @var array{access: string} $data */
        $data = $request->validated();
        app(UpdateTenantDatabaseUser::class)->handle($request->user(), $user, $data);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Database user updated.')]);

        return to_route('tenant-databases.edit', $tenantDatabase);
    }

    public function rotatePassword(Request $request, TenantDatabase $tenantDatabase, TenantDatabaseUser $user): RedirectResponse
    {
        $password = app(RotateTenantDatabaseUserPassword::class)->handle($request->user(), $user);

        $this->flashPassword($tenantDatabase, $user, $password);
        Inertia::flash('toast', ['type' => 'success', 'message' => __('Database user password rotated.')]);

        return to_route('tenant-databases.edit', $tenantDatabase);
    }

    public function destroy(Request $request, TenantDatabase $tenantDatabase, TenantDatabaseUser $user): RedirectResponse
    {
        app(DeleteTenantDatabaseUser::class)->handle($request->user(), $user);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('Database user deleted.')]);

        return to_route('tenant-databases.edit', $tenantDatabase);
    }

    private function flashPassword(TenantDatabase $tenantDatabase, TenantDatabaseUser $databaseUser, string $password): void
    {
        Inertia::flash('generatedPassword', [
            'uuid' => $tenantDatabase->uuid,
            'label' => $tenantDatabase->label,
            'database_name' => $tenantDatabase->database_name,
            'database_user' => $databaseUser->username,
            'password' => $password,
        ]);
    }
}
