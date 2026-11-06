<?php

namespace Database\Factories;

use App\Models\Node;
use App\Models\NodeAgentUpgrade;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NodeAgentUpgrade>
 */
class NodeAgentUpgradeFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'from_version' => '1.0.89',
            'to_version' => '1.0.90',
            'sha256' => hash('sha256', fake()->unique()->uuid()),
            'token_hash' => NodeAgentUpgrade::hashToken(bin2hex(random_bytes(32))),
            'expires_at' => now()->addHour(),
        ];
    }
}
