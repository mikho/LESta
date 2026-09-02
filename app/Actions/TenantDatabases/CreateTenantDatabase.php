<?php

namespace App\Actions\TenantDatabases;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesTenantDatabaseCapableNode;
use App\Enums\ProvisioningVerb;
use App\Exceptions\ResourceQuotaExceededException;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\PackageLimit;
use App\Models\TenantDatabase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class CreateTenantDatabase
{
    /**
     * @param  array<string, mixed>  $data  Expected shape: array{label: string}
     * @return array{0: TenantDatabase, 1: string} The created row and its one-time plaintext
     *                                             password (never stored anywhere in cleartext
     *                                             past this call: it is encrypted at rest on
     *                                             the row itself, and this is the only place it
     *                                             is ever handed back to a caller).
     */
    public function handle(User $actor, Account $account, array $data): array
    {
        Gate::forUser($actor)->authorize('create', [TenantDatabase::class, $account]);

        return DB::transaction(function () use ($actor, $account, $data): array {
            $limit = PackageLimit::query()
                ->where('package_id', $account->package_id)
                ->where('resource_type', 'tenant_databases')
                ->first();

            if ($limit === null) {
                throw ResourceQuotaExceededException::notConfigured('tenant_databases');
            }

            if ($limit->limit_value !== null && $account->tenantDatabases()->count() >= $limit->limit_value) {
                throw ResourceQuotaExceededException::limitReached('tenant_databases', $limit->limit_value);
            }

            [$node, $capability] = app(ResolvesTenantDatabaseCapableNode::class)->resolve();

            $label = $data['label'];
            $databaseName = TenantDatabase::deriveDatabaseName($account->id, $label);
            $password = bin2hex(random_bytes(24));

            $tenantDatabase = TenantDatabase::query()->create([
                'account_id' => $account->id,
                'node_id' => $node->id,
                'label' => $label,
                'database_name' => $databaseName,
                'database_user' => $databaseName,
                'password' => $password,
                'desired_state_version' => 1,
            ]);

            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $tenantDatabase->getMorphClass(),
                'auditable_id' => $tenantDatabase->getKey(),
                'action' => 'tenant_database.created',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $tenantDatabase,
                $capability,
                ProvisioningVerb::Create,
                $tenantDatabase->toProvisioningPayload(includePassword: true, plaintextPassword: $password),
                $correlationId,
                1,
            );

            return [$tenantDatabase, $password];
        });
    }
}
