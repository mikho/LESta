<?php

namespace Database\Factories;

use App\Models\WebDomainProtectedDir;
use App\Models\WebDomainProtectedDirUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebDomainProtectedDirUser>
 */
class WebDomainProtectedDirUserFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'protected_dir_id' => WebDomainProtectedDir::factory(),
            'username' => fake()->unique()->userName(),
            'password_hash' => WebDomainProtectedDirUser::hashPassword('a-long-test-password'),
        ];
    }
}
