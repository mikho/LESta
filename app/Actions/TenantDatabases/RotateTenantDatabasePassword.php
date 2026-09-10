<?php

namespace App\Actions\TenantDatabases;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesTenantDatabaseCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\TenantDatabase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

/**
 * There is no generic UpdateTenantDatabase action: database_name/database_user/label are all
 * immutable after creation (renaming a live MariaDB schema is a RENAME DATABASE-shaped operation
 * MariaDB doesn't even support as one statement, and nothing in this phase needs it), leaving the
 * password as the only mutable field -- which gets this dedicated verb instead of a generic
 * update. This is also why the Go capability's own `update` handler can assume its payload
 * always contains a password: this action is the only thing that ever issues `update` for
 * database.tenant.v1 at all.
 *
 * Rotates the companion statistics account's password in the same operation, lockstep: there is
 * no independent rotation trigger for it today (see TenantDatabase's own class doc comment).
 */
class RotateTenantDatabasePassword
{
    /**
     * @return array{0: TenantDatabase, 1: string} The row and its new one-time plaintext
     *                                             password (the tenant's own; the rotated
     *                                             statistics-account password is never handed
     *                                             back here, since nothing outside this
     *                                             provisioning round trip consumes it yet).
     */
    public function handle(User $actor, TenantDatabase $tenantDatabase): array
    {
        Gate::forUser($actor)->authorize('update', $tenantDatabase);

        return DB::transaction(function () use ($actor, $tenantDatabase): array {
            $password = bin2hex(random_bytes(24));
            $statsPassword = bin2hex(random_bytes(24));

            $tenantDatabase->forceFill([
                'password' => $password,
                'stats_password' => $statsPassword,
                'desired_state_version' => $tenantDatabase->desired_state_version + 1,
            ])->save();

            $capability = app(ResolvesTenantDatabaseCapableNode::class)->resolveFor($tenantDatabase->node);
            $correlationId = (string) Str::uuid();

            // Deliberately never logs either password itself, only that a rotation happened.
            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $tenantDatabase->getMorphClass(),
                'auditable_id' => $tenantDatabase->getKey(),
                'action' => 'tenant_database.password_rotated',
                'correlation_id' => $correlationId,
            ]);

            app(RecordsProvisioningOperation::class)->record(
                $tenantDatabase,
                $capability,
                ProvisioningVerb::Update,
                $tenantDatabase->toProvisioningPayload(includePassword: true, plaintextPassword: $password, statsPlaintextPassword: $statsPassword),
                $correlationId,
                $tenantDatabase->desired_state_version,
            );

            return [$tenantDatabase, $password];
        });
    }
}
