<?php

namespace App\Actions\Provisioning;

use App\Enums\NodeCapabilityType;
use App\Exceptions\NoBackupCapableNodeAvailableException;
use App\Models\Node;

class ResolvesBackupCapableNode
{
    private const NodeCapabilityType CAPABILITY = NodeCapabilityType::BackupEncryptedArtifacts;

    /**
     * Pick the given node if it has an active backup.encrypted-artifacts.v1 capability. Unlike
     * ResolvesMailCapableNode's resolve(), a backup is never auto-assigned to "the first available
     * node": a backup is inherently node-scoped to the node it snapshots, so the caller always
     * already knows which node it wants.
     */
    public function resolveFor(Node $node): string
    {
        if ($node->hasCapability(self::CAPABILITY)) {
            return self::CAPABILITY->value;
        }

        throw new NoBackupCapableNodeAvailableException;
    }
}
