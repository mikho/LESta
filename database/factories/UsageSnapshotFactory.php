<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\MailAccount;
use App\Models\Node;
use App\Models\UsageSnapshot;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageSnapshot>
 */
class UsageSnapshotFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'node_id' => Node::factory(),
            'disk_bytes' => fake()->numberBetween(0, 1_000_000_000),
            'request_count' => null,
            'bytes_sent' => null,
            'collected_at' => now(),
        ];
    }

    /**
     * A snapshotable is required (morphs() has no nullable column); default to a real MailAccount
     * unless the caller already set one via ->for($model, 'snapshotable').
     */
    public function configure(): static
    {
        return $this->afterMaking(function (UsageSnapshot $snapshot): void {
            if ($snapshot->getAttribute('snapshotable_type') === null) {
                $mailAccount = MailAccount::factory()->create();
                $snapshot->snapshotable_type = $mailAccount->getMorphClass();
                $snapshot->snapshotable_id = $mailAccount->id;
            }
        });
    }

    public function webUsage(): static
    {
        return $this->state(fn (): array => [
            'disk_bytes' => null,
            'request_count' => fake()->numberBetween(0, 100_000),
            'bytes_sent' => fake()->numberBetween(0, 1_000_000_000),
        ]);
    }
}
