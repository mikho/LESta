<?php

namespace Database\Factories;

use App\Models\Node;
use App\Models\NodeAdminGrant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NodeAdminGrant>
 */
class NodeAdminGrantFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'node_id' => Node::factory(),
        ];
    }
}
