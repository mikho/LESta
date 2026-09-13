<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\MailAccount;
use App\Models\Node;
use App\Models\UsageSnapshotRollup;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UsageSnapshotRollup>
 */
class UsageSnapshotRollupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'node_id' => Node::factory(),
            'period' => now()->startOfMonth(),
            'disk_bytes_last' => fake()->numberBetween(0, 1_000_000_000),
            'request_count_sum' => null,
            'bytes_sent_sum' => null,
        ];
    }

    /**
     * A snapshotable is required (morphs() has no nullable column); default to a real MailAccount
     * unless the caller already set one via ->for($model, 'snapshotable').
     */
    public function configure(): static
    {
        return $this->afterMaking(function (UsageSnapshotRollup $rollup): void {
            if ($rollup->getAttribute('snapshotable_type') === null) {
                $mailAccount = MailAccount::factory()->create();
                $rollup->snapshotable_type = $mailAccount->getMorphClass();
                $rollup->snapshotable_id = $mailAccount->id;
            }
        });
    }

    public function webUsage(): static
    {
        return $this->state(fn (): array => [
            'disk_bytes_last' => null,
            'request_count_sum' => fake()->numberBetween(0, 100_000),
            'bytes_sent_sum' => fake()->numberBetween(0, 1_000_000_000),
        ]);
    }
}
