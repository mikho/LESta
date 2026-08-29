<?php

namespace Database\Factories;

use App\Enums\SslMode;
use App\Enums\SuspensionSource;
use App\Enums\WebServer;
use App\Models\Account;
use App\Models\IpAllocation;
use App\Models\Node;
use App\Models\WebDomain;
use App\Models\WebDomainAlias;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WebDomain>
 */
class WebDomainFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'node_id' => Node::factory(),
            'ip_allocation_id' => IpAllocation::factory(),
            'domain' => WebDomain::normalizeDomain(fake()->unique()->domainName()),
            'web_template' => 'default',
            'web_server' => WebServer::Nginx,
            'ssl_mode' => SslMode::None,
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

    public function withAlias(): static
    {
        return $this->afterCreating(function (WebDomain $webDomain): void {
            WebDomainAlias::factory()->for($webDomain)->create();
        });
    }
}
