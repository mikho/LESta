<?php

namespace Database\Factories;

use App\Enums\ProvisioningStatus;
use App\Models\MetricsCollection;
use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MetricsCollection>
 */
class MetricsCollectionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'status' => ProvisioningStatus::Pending,
            'desired_state_version' => 1,
            'error_message' => null,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => ProvisioningStatus::Applied,
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => ProvisioningStatus::Failed,
            'error_message' => 'a real failure reason',
            'completed_at' => now(),
        ]);
    }
}
