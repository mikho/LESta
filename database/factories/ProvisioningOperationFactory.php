<?php

namespace Database\Factories;

use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\Account;
use App\Models\ProvisioningOperation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProvisioningOperation>
 */
class ProvisioningOperationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'capability' => 'web.nginx.v1',
            'operation' => ProvisioningVerb::Create,
            'status' => ProvisioningStatus::Pending,
            'desired_state_version' => 1,
            'payload' => [],
            'protocol_version' => '1',
            'correlation_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'issued_at' => now(),
            'request_digest' => 'sha256:'.hash('sha256', (string) Str::uuid()),
            'attempts' => 0,
        ];
    }

    /**
     * Ensure a provisionable (and its resource_id) exist unless the caller already set one.
     */
    public function configure(): static
    {
        return $this->afterMaking(function (ProvisioningOperation $operation): void {
            if ($operation->getAttribute('provisionable_type') === null) {
                $account = Account::factory()->create();
                $operation->provisionable_type = $account->getMorphClass();
                $operation->provisionable_id = $account->id;
                $operation->resource_id = $account->uuid;
            }
        });
    }

    public function pending(): static
    {
        return $this->state(fn (): array => ['status' => ProvisioningStatus::Pending]);
    }

    public function dispatched(): static
    {
        return $this->state(fn (): array => [
            'status' => ProvisioningStatus::Dispatched,
            'dispatched_at' => now(),
            'deadline' => now()->addMinutes(5),
        ]);
    }

    public function applied(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => ProvisioningStatus::Applied,
            'observed_state_version' => $attributes['desired_state_version'] ?? 1,
            'observed_state_digest' => 'sha256:'.hash('sha256', 'applied'),
            'generation_id' => (string) Str::uuid(),
            'completed_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn (): array => [
            'status' => ProvisioningStatus::Rejected,
            'errors' => [['code' => 'rejected', 'message' => 'Rejected by provisioner.']],
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => ProvisioningStatus::Failed,
            'errors' => [['code' => 'failed', 'message' => 'Provisioner failed to apply.']],
            'completed_at' => now(),
        ]);
    }

    public function degraded(): static
    {
        return $this->state(fn (): array => [
            'status' => ProvisioningStatus::Degraded,
            'errors' => [['code' => 'degraded', 'message' => 'Node reported degraded state.']],
            'completed_at' => now(),
        ]);
    }
}
