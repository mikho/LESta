<?php

namespace Database\Factories;

use App\Models\Package;
use App\Models\PackageLimit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PackageLimit>
 */
class PackageLimitFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'package_id' => Package::factory(),
            'resource_type' => fake()->randomElement(['memberships', 'web_domains', 'mailboxes']),
            'limit_value' => fake()->numberBetween(1, 100),
        ];
    }
}
