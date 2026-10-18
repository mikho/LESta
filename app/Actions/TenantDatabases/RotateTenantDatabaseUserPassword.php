<?php

namespace App\Actions\TenantDatabases;

use App\Models\TenantDatabaseUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class RotateTenantDatabaseUserPassword
{
    /**
     * @return string The new one-time plaintext password, handed back only here and never stored.
     */
    public function handle(User $actor, TenantDatabaseUser $databaseUser): string
    {
        $tenantDatabase = $databaseUser->tenantDatabase;
        Gate::forUser($actor)->authorize('update', $tenantDatabase);

        return DB::transaction(function () use ($actor, $tenantDatabase, $databaseUser): string {
            $password = bin2hex(random_bytes(24));

            app(RecordsTenantDatabaseUserChange::class)->record($actor, $tenantDatabase, $databaseUser, 'tenant_database.user_password_rotated', $password);

            return $password;
        });
    }
}
