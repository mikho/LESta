<?php

namespace App\Services\AgentUpgrade;

/**
 * The exact bytes an administrator signs to let one node install one agent binary. The node
 * (agentupgrade.SignedMessage in the agent) and the browser build the same string; any change here
 * must be made in all three places.
 */
final class AgentUpgradeMessage
{
    public const string PREFIX = 'lesta-agent-upgrade/v1';

    public static function build(string $nodeUuid, string $version, string $sha256, int $expiresAt, string $nextPublicKey): string
    {
        return implode("\n", [self::PREFIX, $nodeUuid, $version, $sha256, (string) $expiresAt, $nextPublicKey]);
    }
}
