<?php

namespace App\Actions\TenantDatabases;

use App\Enums\DatabaseUserAccess;
use App\Models\TenantDatabase;
use App\Models\TenantDatabaseUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class CreateTenantDatabaseUser
{
    /**
     * @param  array{label: string, access: string}  $data
     * @return array{0: TenantDatabaseUser, 1: string} The user and its one-time plaintext password,
     *                                                 handed back only here and never stored.
     */
    public function handle(User $actor, TenantDatabase $tenantDatabase, array $data): array
    {
        Gate::forUser($actor)->authorize('update', $tenantDatabase);

        return DB::transaction(function () use ($actor, $tenantDatabase, $data): array {
            if ($tenantDatabase->users()->lockForUpdate()->count() >= TenantDatabaseUser::MAX_PER_DATABASE) {
                throw ValidationException::withMessages(['label' => __('A database can have at most :max extra users.', ['max' => TenantDatabaseUser::MAX_PER_DATABASE])]);
            }

            $password = bin2hex(random_bytes(24));

            $databaseUser = $tenantDatabase->users()->create([
                'label' => $data['label'],
                'username' => TenantDatabaseUser::deriveUsername($tenantDatabase, $data['label']),
                'access' => DatabaseUserAccess::from($data['access']),
            ]);

            app(RecordsTenantDatabaseUserChange::class)->record($actor, $tenantDatabase, $databaseUser, 'tenant_database.user_created', $password);

            return [$databaseUser, $password];
        });
    }
}
