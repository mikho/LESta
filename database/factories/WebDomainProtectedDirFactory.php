<?php

namespace Database\Factories;

use App\Models\WebDomain;
use App\Models\WebDomainProtectedDir;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebDomainProtectedDir>
 */
class WebDomainProtectedDirFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'web_domain_id' => WebDomain::factory(),
            'path' => '/'.fake()->unique()->slug(2),
            'realm' => 'Members area',
        ];
    }
}
