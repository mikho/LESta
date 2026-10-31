<?php

namespace App\Actions\Provisioning;

use App\Enums\NodeCapabilityType;
use App\Exceptions\NoCronCapableNodeAvailableException;
use App\Models\Node;

class ResolvesCronCapableNode
{
    private const NodeCapabilityType CAPABILITY = NodeCapabilityType::SchedulerCron;

    /**
     * Pick the first non-suspended node with an active scheduler.account-cron.v1 capability.
     *
     * @return array{0: Node, 1: string}
     */
    public function resolve(): array
    {
        $node = Node::firstWithActiveCapability(self::CAPABILITY);

        if ($node !== null) {
            return [$node, self::CAPABILITY->value];
        }

        throw new NoCronCapableNodeAvailableException;
    }

    /**
     * Resolve the active cron capability for an already-assigned node. Used by actions that
     * never reassign a cron job's node.
     */
    public function resolveFor(Node $node): string
    {
        if ($node->hasCapability(self::CAPABILITY)) {
            return self::CAPABILITY->value;
        }

        throw new NoCronCapableNodeAvailableException;
    }
}
