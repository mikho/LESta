<?php

namespace Database\Factories;

use App\Models\ApiToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ApiToken>
 */
class ApiTokenFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'account_id' => null,
            'name' => fake()->words(2, true),
            'token_hash' => ApiToken::hashToken(ApiToken::generatePlaintext()),
            'abilities' => ['web:read'],
            'expires_at' => now()->addDays(ApiToken::DEFAULT_DAYS),
        ];
    }

    /**
     * A token whose plaintext is known to the test.
     */
    public function withPlaintext(string $plaintext): static
    {
        return $this->state(fn (): array => ['token_hash' => ApiToken::hashToken($plaintext)]);
    }

    public function expired(): static
    {
        return $this->state(fn (): array => ['expires_at' => now()->subMinute()]);
    }
}
