<?php

namespace App\Actions\Provisioning;

use App\Exceptions\NoWebCapableNodeAvailableException;
use App\Models\Node;

class ResolvesWebCapableNode
{
    /**
     * Pick the first non-suspended node with an active web.nginx.v1 capability, falling back to
     * web.apache.v1. When a node has both active (the "both" profile), nginx always wins,
     * matching ADR 0002 §6 (nginx always owns the public listener whenever present).
     *
     * @return array{0: Node, 1: string}
     */
    public function resolve(): array
    {
        foreach (['web.nginx.v1', 'web.apache.v1'] as $capability) {
            $node = Node::query()
                ->whereNull('suspended_at')
                ->whereHas('capabilities', function ($query) use ($capability): void {
                    $query->where('capability', $capability)->whereNull('suspended_at');
                })
                ->first();

            if ($node !== null) {
                return [$node, $capability];
            }
        }

        throw new NoWebCapableNodeAvailableException;
    }

    /**
     * Resolve the active web capability for an already-assigned node, applying the same
     * nginx-over-apache priority. Used by actions that never reassign a domain's node.
     */
    public function resolveFor(Node $node): string
    {
        foreach (['web.nginx.v1', 'web.apache.v1'] as $capability) {
            $active = $node->capabilities()
                ->where('capability', $capability)
                ->whereNull('suspended_at')
                ->exists();

            if ($active) {
                return $capability;
            }
        }

        throw new NoWebCapableNodeAvailableException;
    }
}
