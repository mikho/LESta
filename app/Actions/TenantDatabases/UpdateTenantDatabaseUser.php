<?php

namespace App\Actions\TenantDatabases;

use App\Enums\DatabaseUserAccess;
use App\Models\TenantDatabaseUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Changes an extra user's access. The label, and so the user name, is
 * fixed once created; the password has RotateTenantDatabaseUserPassword.
 */
class UpdateTenantDatabaseUser
{
    /**
     * @param  array{access: string}  $data
     */
    public function handle(User $actor, TenantDatabaseUser $databaseUser, array $data): TenantDatabaseUser
    {
        $tenantDatabase = $databaseUser->tenantDatabase;
        Gate::forUser($actor)->authorize('update', $tenantDatabase);

        $access = DatabaseUserAccess::from($data['access']);
        if ($databaseUser->access === $access) {
            return $databaseUser;
        }

        DB::transaction(function () use ($actor, $tenantDatabase, $databaseUser, $access): void {
            $databaseUser->update(['access' => $access]);

            app(RecordsTenantDatabaseUserChange::class)->record($actor, $tenantDatabase, $databaseUser, 'tenant_database.user_updated');
        });

        return $databaseUser;
    }
}
