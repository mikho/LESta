<?php

namespace Database\Factories;

use App\Models\Node;
use App\Models\NodeServiceAction;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NodeServiceAction>
 */
class NodeServiceActionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'user_id' => null,
            'unit' => 'nginx',
            'action' => 'restart',
        ];
    }
}
