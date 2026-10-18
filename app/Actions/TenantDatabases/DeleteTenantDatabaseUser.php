<?php

namespace App\Actions\TenantDatabases;

use App\Models\TenantDatabaseUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Removes an extra user: the row goes first, so the database's payload no
 * longer lists it and the agent drops the MariaDB user.
 */
class DeleteTenantDatabaseUser
{
    public function handle(User $actor, TenantDatabaseUser $databaseUser): void
    {
        $tenantDatabase = $databaseUser->tenantDatabase;
        Gate::forUser($actor)->authorize('update', $tenantDatabase);

        DB::transaction(function () use ($actor, $tenantDatabase, $databaseUser): void {
            $databaseUser->delete();

            app(RecordsTenantDatabaseUserChange::class)->record($actor, $tenantDatabase, $databaseUser, 'tenant_database.user_deleted');
        });
    }
}
