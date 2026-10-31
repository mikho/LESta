<?php

namespace App\Actions\Provisioning;

use App\Enums\NodeCapabilityType;
use App\Exceptions\NoMailCapableNodeAvailableException;
use App\Models\Node;

class ResolvesMailCapableNode
{
    private const NodeCapabilityType CAPABILITY = NodeCapabilityType::MailSmtpImap;

    /**
     * Pick the first non-suspended node with an active mail.smtp-imap.v1 capability.
     *
     * @return array{0: Node, 1: string}
     */
    public function resolve(): array
    {
        $node = Node::firstWithActiveCapability(self::CAPABILITY);

        if ($node !== null) {
            return [$node, self::CAPABILITY->value];
        }

        throw new NoMailCapableNodeAvailableException;
    }

    /**
     * Resolve the active mail capability for an already-assigned node. Used by actions that
     * never reassign a domain's node.
     */
    public function resolveFor(Node $node): string
    {
        if ($node->hasCapability(self::CAPABILITY)) {
            return self::CAPABILITY->value;
        }

        throw new NoMailCapableNodeAvailableException;
    }
}
