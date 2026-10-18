<?php

namespace App\Actions\TenantDatabases;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesTenantDatabaseCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\TenantDatabase;
use App\Models\TenantDatabaseUser;
use App\Models\User;
use Illuminate\Support\Str;

/**
 * The shared tail of every extra-user change: the database's next desired
 * state version, an audit event naming the user, and one `update` for the
 * database carrying its whole user list. The agent reconciles that list
 * against what it last applied, so adding, changing and removing a user are
 * all the same operation. Called inside the caller's transaction.
 */
class RecordsTenantDatabaseUserChange
{
    /**
     * @param  string|null  $plaintextPassword  The user's new password, for its creation or a rotation only.
     */
    public function record(User $actor, TenantDatabase $tenantDatabase, TenantDatabaseUser $databaseUser, string $action, ?string $plaintextPassword = null): void
    {
        $tenantDatabase->forceFill(['desired_state_version' => $tenantDatabase->desired_state_version + 1])->save();

        $capability = app(ResolvesTenantDatabaseCapableNode::class)->resolveFor($tenantDatabase->node);
        $correlationId = (string) Str::uuid();

        // Never the password itself, only which user changed and how.
        AuditEvent::create([
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->getKey(),
            'auditable_type' => $tenantDatabase->getMorphClass(),
            'auditable_id' => $tenantDatabase->getKey(),
            'action' => $action,
            'correlation_id' => $correlationId,
            'metadata' => ['username' => $databaseUser->username, 'access' => $databaseUser->access->value],
        ]);

        app(RecordsProvisioningOperation::class)->record(
            $tenantDatabase,
            $capability,
            ProvisioningVerb::Update,
            $tenantDatabase->toProvisioningPayload(userPasswords: $plaintextPassword === null ? [] : [$databaseUser->id => $plaintextPassword]),
            $correlationId,
            $tenantDatabase->desired_state_version,
        );
    }
}
