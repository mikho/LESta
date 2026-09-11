<?php

namespace Database\Factories;

use App\Enums\RoleScope;
use App\Models\Account;
use App\Models\Membership;
use App\Models\Permission;
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

    /**
     * A provider admin with the full permission catalog attached, matching
     * PermissionSeeder's own real-world grant: tests that build an admin
     * this way keep today's blanket-everything behavior without needing to
     * run the real seeders. A test that wants to prove finer-grained
     * permission gating instead builds its own platform-scope Role with
     * only a subset of Permission::CATALOG attached, rather than using this
     * state.
     */
    public function providerAdmin(): static
    {
        return $this->state(function (): array {
            $role = Role::query()->firstOrCreate(
                ['name' => 'provider_admin'],
                ['scope' => RoleScope::Platform]
            );

            $role->permissions()->syncWithoutDetaching(
                collect(Permission::CATALOG)->map(fn (string $name): int => Permission::query()->firstOrCreate(['name' => $name])->id)
            );

            return [
                'account_id' => null,
                'role_id' => $role->id,
            ];
        });
    }
}
