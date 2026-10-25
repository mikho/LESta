<?php

namespace App\Actions\TenantDatabases;

use App\Enums\DatabaseUserAccess;
use App\Models\TenantDatabaseUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Changes an extra user's access, its remote hosts, or both. The label, and
 * so the user name, is fixed once created; the password has
 * RotateTenantDatabaseUserPassword.
 */
class UpdateTenantDatabaseUser
{
    /**
     * @param  array{access?: string, remote_hosts?: list<string>}  $data
     */
    public function handle(User $actor, TenantDatabaseUser $databaseUser, array $data): TenantDatabaseUser
    {
        $tenantDatabase = $databaseUser->tenantDatabase;
        Gate::forUser($actor)->authorize('update', $tenantDatabase);

        $access = isset($data['access']) ? DatabaseUserAccess::from($data['access']) : $databaseUser->access;
        $remoteHosts = array_key_exists('remote_hosts', $data) ? $data['remote_hosts'] : $databaseUser->remoteHosts();
        if ($databaseUser->access === $access && $databaseUser->remoteHosts() === $remoteHosts) {
            return $databaseUser;
        }

        DB::transaction(function () use ($actor, $tenantDatabase, $databaseUser, $access, $remoteHosts): void {
            $databaseUser->update(['access' => $access, 'remote_hosts' => $remoteHosts]);

            app(RecordsTenantDatabaseUserChange::class)->record($actor, $tenantDatabase, $databaseUser, 'tenant_database.user_updated');
        });

        return $databaseUser;
    }
}
