<?php

namespace App\Actions\TenantDatabases;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesTenantDatabaseCapableNode;
use App\Enums\ProvisioningVerb;
use App\Enums\SuspensionSource;
use App\Models\AuditEvent;
use App\Models\TenantDatabase;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class SuspendTenantDatabase
{
    public function handle(User $actor, TenantDatabase $tenantDatabase, SuspensionSource $source = SuspensionSource::Manual): void
    {
        Gate::forUser($actor)->authorize('suspend', $tenantDatabase);

        if ($tenantDatabase->isSuspended()) {
            return; // duplicate submission: no second audit row
        }

        DB::transaction(function () use ($actor, $tenantDatabase, $source): void {
            $tenantDatabase->suspend($source);
            $tenantDatabase->forceFill(['desired_state_version' => $tenantDatabase->desired_state_version + 1])->save();

            $capability = app(ResolvesTenantDatabaseCapableNode::class)->resolveFor($tenantDatabase->node);
            $correlationId = (string) Str::uuid();

            AuditEvent::create([
                'actor_type' => $actor->getMorphClass(),
                'actor_id' => $actor->getKey(),
                'auditable_type' => $tenantDatabase->getMorphClass(),
                'auditable_id' => $tenantDatabase->getKey(),
                'action' => 'tenant_database.suspended',
                'correlation_id' => $correlationId,
                'metadata' => ['source' => $source->value],
            ]);

            // No password: suspend never carries credentials in its payload, per the ADR's
            // "database credentials are never included in normal desired-state payloads"
            // restriction -- only create and the dedicated password-rotate operation do.
            app(RecordsProvisioningOperation::class)->record(
                $tenantDatabase,
                $capability,
                ProvisioningVerb::Suspend,
                $tenantDatabase->toProvisioningPayload(),
                $correlationId,
                $tenantDatabase->desired_state_version,
            );
        });
    }
}
