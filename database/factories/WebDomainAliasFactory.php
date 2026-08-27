<?php

namespace Database\Factories;

use App\Models\WebDomain;
use App\Models\WebDomainAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebDomainAlias>
 */
class WebDomainAliasFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'web_domain_id' => WebDomain::factory(),
            'alias' => WebDomain::normalizeDomain(fake()->unique()->domainName()),
        ];
    }
}
