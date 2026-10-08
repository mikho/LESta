<?php

namespace Database\Factories;

use App\Models\WebDomain;
use App\Models\WebDomainRedirect;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebDomainRedirect>
 */
class WebDomainRedirectFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'web_domain_id' => WebDomain::factory(),
            'source' => '/'.fake()->unique()->slug(2),
            'target' => '/new-home',
            'status' => 301,
            'prefix' => false,
        ];
    }
}
