<?php

namespace App\Actions\Provisioning;

use App\Exceptions\NoTenantDatabaseCapableNodeAvailableException;
use App\Models\Node;

class ResolvesTenantDatabaseCapableNode
{
    private const CAPABILITY = 'database.tenant.v1';

    /**
     * Pick the first non-suspended node with an active database.tenant.v1 capability.
     *
     * @return array{0: Node, 1: string}
     */
    public function resolve(): array
    {
        $node = Node::query()
            ->whereNull('suspended_at')
            ->whereHas('capabilities', function ($query): void {
                $query->where('capability', self::CAPABILITY)->whereNull('suspended_at');
            })
            ->first();

        if ($node !== null) {
            return [$node, self::CAPABILITY];
        }

        throw new NoTenantDatabaseCapableNodeAvailableException;
    }

    /**
     * Resolve the active tenant-database capability for an already-assigned node. Used by
     * actions that never reassign a database's node.
     */
    public function resolveFor(Node $node): string
    {
        $active = $node->capabilities()
            ->where('capability', self::CAPABILITY)
            ->whereNull('suspended_at')
            ->exists();

        if ($active) {
            return self::CAPABILITY;
        }

        throw new NoTenantDatabaseCapableNodeAvailableException;
    }
}
