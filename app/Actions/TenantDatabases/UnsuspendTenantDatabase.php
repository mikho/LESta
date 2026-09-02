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

class UnsuspendTenantDatabase
{
    public function handle(User $actor, TenantDatabase $tenantDatabase): void
    {
        Gate::forUser($actor)->authorize('unsuspend', $tenantDatabase);

        if (! $tenantDatabase->isSuspended()) {
            return;
        }

        DB::transaction(function () use ($actor, $tenantDatabase): void {
            $tenantDatabase->unsuspend();
            $tenantDatabase->forceFill(['desired_state_version' => $tenantDatabase->desired_state_version + 1])->save();

            $capability = app(ResolvesTenantDatabaseCapableNode::class)->resolveFor($tenantDatabase->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $tenantDatabase->getMorphClass(),
                'auditable_id' => $tenantDatabase->getKey(),
                'action' => 'tenant_database.unsuspended',
                'correlation_id' => $correlationId,
            ]);

            // No password: unsuspend restores access via GRANT alone, never re-supplying (or
            // even touching) the credential.
            app(RecordsProvisioningOperation::class)->record(
                $tenantDatabase,
                $capability,
                ProvisioningVerb::Unsuspend,
                $tenantDatabase->toProvisioningPayload(),
                $correlationId,
                $tenantDatabase->desired_state_version,
            );
        });
    }
}
