<?php

namespace App\Actions\Provisioning;

use App\Exceptions\NoDnsCapableNodeAvailableException;
use App\Models\Node;

class ResolvesDnsCapableNode
{
    private const CAPABILITY = 'dns.bind9.v1';

    /**
     * Pick the first non-suspended node with an active dns.bind9.v1 capability.
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

        throw new NoDnsCapableNodeAvailableException;
    }

    /**
     * Resolve the active dns capability for an already-assigned node. Used by actions that
     * never reassign a zone's node.
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

        throw new NoDnsCapableNodeAvailableException;
    }
}
