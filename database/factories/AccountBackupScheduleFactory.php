<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountBackupSchedule;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountBackupSchedule>
 */
class AccountBackupScheduleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return ['account_id' => Account::factory(), 'frequency' => 'off', 'parts' => ['files', 'databases', 'mail']];
    }

    public function daily(): static
    {
        return $this->state(['frequency' => 'daily', 'next_run_at' => now()->subMinute()]);
    }
}
