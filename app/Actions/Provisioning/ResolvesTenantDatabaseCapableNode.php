<?php

namespace App\Actions\Provisioning;

use App\Enums\NodeCapabilityType;
use App\Exceptions\NoTenantDatabaseCapableNodeAvailableException;
use App\Models\Node;

class ResolvesTenantDatabaseCapableNode
{
    private const NodeCapabilityType CAPABILITY = NodeCapabilityType::DatabaseTenant;

    /**
     * Pick the first non-suspended node with an active database.tenant.v1 capability.
     *
     * @return array{0: Node, 1: string}
     */
    public function resolve(): array
    {
        $node = Node::firstWithActiveCapability(self::CAPABILITY);

        if ($node !== null) {
            return [$node, self::CAPABILITY->value];
        }

        throw new NoTenantDatabaseCapableNodeAvailableException;
    }

    /**
     * Resolve the active tenant-database capability for an already-assigned node. Used by
     * actions that never reassign a database's node.
     */
    public function resolveFor(Node $node): string
    {
        if ($node->hasCapability(self::CAPABILITY)) {
            return self::CAPABILITY->value;
        }

        throw new NoTenantDatabaseCapableNodeAvailableException;
    }
}
