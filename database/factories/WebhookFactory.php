<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\Webhook;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Webhook>
 */
class WebhookFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'url' => 'https://hooks.example.com/lesta',
            'secret' => Webhook::generateSecret(),
            'events' => Webhook::EVENTS,
            'description' => null,
        ];
    }

    public function platformWide(): static
    {
        return $this->state(fn (): array => ['account_id' => null]);
    }
}
