<?php

namespace Database\Factories;

use App\Enums\IpAllocationStatus;
use App\Models\IpAllocation;
use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<IpAllocation>
 */
class IpAllocationFactory extends Factory
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
            'ip_address' => fake()->unique()->ipv4(),
            'status' => IpAllocationStatus::Shared,
            'account_id' => null,
        ];
    }

    public function dedicated(): static
    {
        return $this->state(fn (): array => [
            'status' => IpAllocationStatus::Dedicated,
        ]);
    }
}
