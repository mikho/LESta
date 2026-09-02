<?php

namespace Database\Factories;

use App\Enums\SuspensionSource;
use App\Models\Account;
use App\Models\Node;
use App\Models\TenantDatabase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantDatabase>
 */
class TenantDatabaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $label = fake()->unique()->regexify('[a-z][a-z0-9_]{2,10}');
        $databaseName = 'lesta_'.fake()->unique()->numberBetween(1, 999999).'_'.$label;

        return [
            'account_id' => Account::factory(),
            'node_id' => Node::factory(),
            'label' => $label,
            'database_name' => $databaseName,
            'database_user' => $databaseName,
            'password' => bin2hex(random_bytes(24)),
            'desired_state_version' => 1,
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
}
