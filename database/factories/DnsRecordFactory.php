<?php

namespace Database\Factories;

use App\Enums\DnsRecordType;
use App\Enums\SuspensionSource;
use App\Models\DnsRecord;
use App\Models\DnsZone;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DnsRecord>
 */
class DnsRecordFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'dns_zone_id' => DnsZone::factory(),
            'name' => fake()->unique()->word(),
            'type' => fake()->randomElement(DnsRecordType::cases()),
            'priority' => null,
            'value' => fake()->ipv4(),
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
}
