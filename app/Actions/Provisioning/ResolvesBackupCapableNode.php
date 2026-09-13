<?php

namespace App\Actions\Provisioning;

use App\Exceptions\NoBackupCapableNodeAvailableException;
use App\Models\Node;

class ResolvesBackupCapableNode
{
    private const CAPABILITY = 'backup.encrypted-artifacts.v1';

    /**
     * Pick the given node if it has an active backup.encrypted-artifacts.v1 capability. Unlike
     * ResolvesMailCapableNode's resolve(), a backup is never auto-assigned to "the first available
     * node": a backup is inherently node-scoped to the node it snapshots, so the caller always
     * already knows which node it wants.
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

        throw new NoBackupCapableNodeAvailableException;
    }
}
