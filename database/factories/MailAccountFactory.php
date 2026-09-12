<?php

namespace Database\Factories;

use App\Enums\SuspensionSource;
use App\Models\MailAccount;
use App\Models\MailDomain;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MailAccount>
 */
class MailAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'mail_domain_id' => MailDomain::factory(),
            'local_part' => fake()->unique()->userName(),
            'password' => bin2hex(random_bytes(24)),
            'quota_mb' => 1024,
            'forward_to' => null,
            'forward_only' => false,
            'autoreply_enabled' => false,
            'autoreply_message' => null,
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
