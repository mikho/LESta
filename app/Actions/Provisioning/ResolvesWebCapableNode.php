<?php

namespace App\Actions\Provisioning;

use App\Exceptions\NoWebCapableNodeAvailableException;
use App\Models\Node;

class ResolvesWebCapableNode
{
    /**
     * Pick a web-capable node and the ordered list of capabilities a domain's provisioning
     * operations must be recorded against.
     *
     * Default ($webServer === 'nginx'): the original single-capability contract, preserved
     * exactly for zero regression risk. Picks the first non-suspended node with an active
     * web.nginx.v1 capability, falling back to web.apache.v1, and returns it as a one-element
     * list. When a node has both active (the "both" profile) under this default path, nginx
     * always wins, matching ADR 0002 §6 (nginx always owns the public listener whenever
     * present).
     *
     * $webServer === 'apache': requires an active web.apache.v1 on the resolved node (throwing
     * NoWebCapableNodeAvailableException otherwise). Returns ['web.apache.v1'] alone when the
     * node is apache-only, or ['web.apache.v1', 'web.nginx.v1'] (the real content capability
     * first, the proxy capability second) when the node also has web.nginx.v1 active.
     *
     * @return array{0: Node, 1: list<string>}
     */
    public function resolve(string $webServer = 'nginx'): array
    {
        if ($webServer === 'apache') {
            $node = Node::query()
                ->whereNull('suspended_at')
                ->whereHas('capabilities', function ($query): void {
                    $query->where('capability', 'web.apache.v1')->whereNull('suspended_at');
                })
                ->first();

            if ($node === null) {
                throw new NoWebCapableNodeAvailableException;
            }

            return [$node, $this->apacheCapabilitiesFor($node)];
        }

        foreach (['web.nginx.v1', 'web.apache.v1'] as $capability) {
            $node = Node::query()
                ->whereNull('suspended_at')
                ->whereHas('capabilities', function ($query) use ($capability): void {
                    $query->where('capability', $capability)->whereNull('suspended_at');
                })
                ->first();

            if ($node !== null) {
                return [$node, [$capability]];
            }
        }

        throw new NoWebCapableNodeAvailableException;
    }

    /**
     * Resolve the ordered list of capabilities for an already-assigned node. Used by actions that
     * never reassign a domain's node.
     *
     * Default ($webServer === 'nginx'): the original single-capability, nginx-over-apache
     * priority contract, preserved exactly as a one-element list.
     *
     * $webServer === 'apache': requires an active web.apache.v1 on $node (throwing
     * NoWebCapableNodeAvailableException otherwise); returns ['web.apache.v1'] alone, or
     * ['web.apache.v1', 'web.nginx.v1'] when $node also has web.nginx.v1 active.
     *
     * @return list<string>
     */
    public function resolveFor(Node $node, string $webServer = 'nginx'): array
    {
        if ($webServer === 'apache') {
            return $this->apacheCapabilitiesFor($node);
        }

        foreach (['web.nginx.v1', 'web.apache.v1'] as $capability) {
            $active = $node->capabilities()
                ->where('capability', $capability)
                ->whereNull('suspended_at')
                ->exists();

            if ($active) {
                return [$capability];
            }
        }

        throw new NoWebCapableNodeAvailableException;
    }

    /**
     * @return list<string>
     */
    private function apacheCapabilitiesFor(Node $node): array
    {
        $apacheActive = $node->capabilities()
            ->where('capability', 'web.apache.v1')
            ->whereNull('suspended_at')
            ->exists();

        if (! $apacheActive) {
            throw new NoWebCapableNodeAvailableException;
        }

        $nginxActive = $node->capabilities()
            ->where('capability', 'web.nginx.v1')
            ->whereNull('suspended_at')
            ->exists();

        return $nginxActive ? ['web.apache.v1', 'web.nginx.v1'] : ['web.apache.v1'];
    }
}
