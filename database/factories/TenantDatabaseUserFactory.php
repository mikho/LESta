<?php

namespace Database\Factories;

use App\Enums\DatabaseUserAccess;
use App\Models\TenantDatabase;
use App\Models\TenantDatabaseUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TenantDatabaseUser>
 */
class TenantDatabaseUserFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_database_id' => TenantDatabase::factory(),
            'label' => fake()->unique()->regexify('[a-z][a-z0-9]{2,10}'),
            'username' => fn (array $attributes): string => TenantDatabaseUser::deriveUsername(TenantDatabase::query()->whereKey($attributes['tenant_database_id'])->firstOrFail(), $attributes['label']),
            'access' => DatabaseUserAccess::Full,
        ];
    }

    /**
     * A read-only user.
     */
    public function readOnly(): static
    {
        return $this->state(['access' => DatabaseUserAccess::ReadOnly]);
    }

    /**
     * A user that may also connect from the given addresses.
     *
     * @param  list<string>  $hosts
     */
    public function remote(array $hosts = ['203.0.113.10']): static
    {
        return $this->state(['remote_hosts' => $hosts]);
    }
}
