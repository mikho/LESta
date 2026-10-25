<?php

namespace App\Actions\TenantDatabases;

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Actions\Provisioning\ResolvesTenantDatabaseCapableNode;
use App\Enums\ProvisioningVerb;
use App\Models\Node;
use App\Models\TenantDatabase;
use Illuminate\Support\Str;

/**
 * Hands a new certificate for the node hostname to the node's tenant MariaDB instance, which
 * presents it to remote database clients. The instance has one certificate for all databases and
 * the agent copies it in whenever it applies a database with remote access, so one update of one
 * such database is enough: at its current desired state version, since nothing about the database
 * itself changed. Nothing happens on a node without remote access.
 */
class RefreshRemoteAccessCertificate
{
    public function handle(Node $node): void
    {
        $tenantDatabase = TenantDatabase::query()
            ->where('node_id', $node->id)
            ->whereHas('users', fn ($users) => $users->whereJsonLength('remote_hosts', '>', 0))
            ->orderBy('id')
            ->first();

        if ($tenantDatabase === null) {
            return;
        }

        app(RecordsProvisioningOperation::class)->record(
            $tenantDatabase,
            app(ResolvesTenantDatabaseCapableNode::class)->resolveFor($node),
            ProvisioningVerb::Update,
            $tenantDatabase->toProvisioningPayload(),
            (string) Str::uuid(),
            $tenantDatabase->desired_state_version,
        );
    }
}
