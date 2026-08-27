<?php

namespace Database\Factories;

use App\Enums\RoleScope;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Membership>
 */
class MembershipFactory extends Factory
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
            'account_id' => Account::factory(),
            'role_id' => Role::factory(),
        ];
    }

    public function owner(): static
    {
        return $this->state(fn (): array => [
            'role_id' => Role::query()->firstOrCreate(
                ['name' => 'owner'],
                ['scope' => RoleScope::Account]
            )->id,
        ]);
    }

    public function member(): static
    {
        return $this->state(fn (): array => [
            'role_id' => Role::query()->firstOrCreate(
                ['name' => 'member'],
                ['scope' => RoleScope::Account]
            )->id,
        ]);
    }

    public function providerAdmin(): static
    {
        return $this->state(fn (): array => [
            'account_id' => null,
            'role_id' => Role::query()->firstOrCreate(
                ['name' => 'provider_admin'],
                ['scope' => RoleScope::Platform]
            )->id,
        ]);
    }
}
