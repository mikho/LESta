<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountNodeIdentity;
use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AccountNodeIdentity>
 */
class AccountNodeIdentityFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'account_id' => Account::factory(),
            'node_id' => Node::factory(),
            'system_username' => 'lesta-t'.fake()->unique()->numberBetween(1, 999999),
            'desired_state_version' => 1,
        ];
    }
}
