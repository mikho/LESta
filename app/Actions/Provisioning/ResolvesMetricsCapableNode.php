<?php

namespace App\Actions\Provisioning;

use App\Enums\NodeCapabilityType;
use App\Exceptions\NoMetricsCapableNodeAvailableException;
use App\Models\Node;

class ResolvesMetricsCapableNode
{
    private const NodeCapabilityType CAPABILITY = NodeCapabilityType::MetricsUsage;

    /**
     * Resolve the active metrics capability for an already-known node. Mirrors
     * ResolvesBackupCapableNode::resolveFor() exactly: a usage collection is inherently node-
     * scoped (it measures whatever real resources happen to be hosted on that one node), so there
     * is no "pick any available node" resolve() the way mail/tenant-database provisioning has --
     * the caller (App\Console\Commands\CollectUsageMetrics) already knows which node it means.
     */
    public function resolveFor(Node $node): string
    {
        if ($node->hasCapability(self::CAPABILITY)) {
            return self::CAPABILITY->value;
        }

        throw new NoMetricsCapableNodeAvailableException;
    }

    /**
     * Whether node has an active metrics.usage.v1 capability, without throwing -- used by the
     * scheduled collection command to silently skip a node that was never bootstrapped with this
     * capability, rather than treating every such node as a hard failure.
     */
    public function isAvailableFor(Node $node): bool
    {
        return $node->hasCapability(self::CAPABILITY);
    }
}
