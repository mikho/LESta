<?php

namespace Database\Factories;

use App\Enums\SuspensionSource;
use App\Models\Node;
use App\Models\NodeCapability;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<NodeCapability>
 */
class NodeCapabilityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'capability' => fake()->randomElement(['web.nginx.v1', 'web.apache.v1', 'dns.bind9.v1']),
            'suspended_at' => null,
            'suspension_source' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'suspended_at' => now(),
            'suspension_source' => SuspensionSource::Manual,
        ]);
    }

    public function cascadeSuspended(): static
    {
        return $this->state(fn (): array => [
            'suspended_at' => now(),
            'suspension_source' => SuspensionSource::Cascade,
        ]);
    }
}
