<?php

namespace Database\Factories;

use App\Models\AcmeAccount;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AcmeAccount>
 */
class AcmeAccountFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'contact_email' => $this->faker->safeEmail(),
            'directory_url' => 'https://acme-staging-v02.api.letsencrypt.org/directory',
            'account_key' => "-----BEGIN EC PRIVATE KEY-----\n".base64_encode(random_bytes(64))."\n-----END EC PRIVATE KEY-----\n",
        ];
    }
}
