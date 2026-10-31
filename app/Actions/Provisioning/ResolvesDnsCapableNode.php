<?php

namespace App\Actions\Provisioning;

use App\Enums\NodeCapabilityType;
use App\Exceptions\NoDnsCapableNodeAvailableException;
use App\Models\Node;

class ResolvesDnsCapableNode
{
    private const NodeCapabilityType CAPABILITY = NodeCapabilityType::DnsBind9;

    /**
     * Pick the first non-suspended node with an active dns.bind9.v1 capability.
     *
     * @return array{0: Node, 1: string}
     */
    public function resolve(): array
    {
        $node = Node::firstWithActiveCapability(self::CAPABILITY);

        if ($node !== null) {
            return [$node, self::CAPABILITY->value];
        }

        throw new NoDnsCapableNodeAvailableException;
    }

    /**
     * Resolve the active dns capability for an already-assigned node. Used by actions that
     * never reassign a zone's node.
     */
    public function resolveFor(Node $node): string
    {
        if ($node->hasCapability(self::CAPABILITY)) {
            return self::CAPABILITY->value;
        }

        throw new NoDnsCapableNodeAvailableException;
    }
}
