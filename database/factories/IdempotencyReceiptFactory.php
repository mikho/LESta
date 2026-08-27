<?php

namespace Database\Factories;

use App\Enums\IdempotencyReceiptStatus;
use App\Models\IdempotencyReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<IdempotencyReceipt>
 */
class IdempotencyReceiptFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'scope' => fake()->word(),
            'idempotency_key' => hash('sha256', (string) Str::uuid()),
            'status' => IdempotencyReceiptStatus::Completed,
            'correlation_id' => (string) Str::uuid(),
            'response' => null,
            'expires_at' => null,
        ];
    }
}
