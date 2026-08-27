<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\PackageLimit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Package>
 */
class PackageFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'is_active' => true,
        ];
    }

    public function withLimit(string $resourceType, ?int $value): static
    {
        return $this->afterCreating(function (Package $package) use ($resourceType, $value): void {
            PackageLimit::factory()->for($package)->create([
                'resource_type' => $resourceType,
                'limit_value' => $value,
            ]);
        });
    }
}
