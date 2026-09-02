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

class DeleteTenantDatabase
{
    public function handle(User $actor, TenantDatabase $tenantDatabase): void
    {
        Gate::forUser($actor)->authorize('delete', $tenantDatabase);

        DB::transaction(function () use ($actor, $tenantDatabase): void {
            if ($tenantDatabase->isSuspended()) {
                $tenantDatabase->unsuspend();
            }

            $capability = app(ResolvesTenantDatabaseCapableNode::class)->resolveFor($tenantDatabase->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $tenantDatabase->getMorphClass(),
                'auditable_id' => $tenantDatabase->getKey(),
                'action' => 'tenant_database.deleted',
                'correlation_id' => $correlationId,
            ]);

            // No password: delete only ever needs to identify what to drop.
            app(RecordsProvisioningOperation::class)->record(
                $tenantDatabase,
                $capability,
                ProvisioningVerb::Delete,
                $tenantDatabase->toProvisioningPayload(),
                $correlationId,
                $tenantDatabase->desired_state_version,
            );

            $tenantDatabase->delete();
        });
    }
}
