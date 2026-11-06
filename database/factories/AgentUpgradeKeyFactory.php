<?php

namespace Database\Factories;

use App\Models\AgentUpgradeKey;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgentUpgradeKey>
 */
class AgentUpgradeKeyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'public_key' => base64_encode(sodium_crypto_sign_publickey(sodium_crypto_sign_keypair())),
        ];
    }
}
