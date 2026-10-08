<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountIpRule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountIpRule>
 */
class AccountIpRuleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'action' => 'deny',
            'cidr' => fake()->unique()->ipv4(),
            'note' => null,
        ];
    }

    public function allow(): static
    {
        return $this->state(['action' => 'allow']);
    }
}
