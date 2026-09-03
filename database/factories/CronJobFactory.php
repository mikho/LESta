<?php

namespace Database\Factories;

use App\Enums\SuspensionSource;
use App\Models\Account;
use App\Models\CronJob;
use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CronJob>
 */
class CronJobFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'node_id' => Node::factory(),
            'minute' => '*',
            'hour' => '*',
            'day_of_month' => '*',
            'month' => '*',
            'day_of_week' => '*',
            'command' => fake()->randomElement([
                '/usr/bin/php /var/www/artisan schedule:run',
                'php artisan backup:run',
                '/usr/bin/wget -q -O - https://example.test/cron.php',
            ]),
            'desired_state_version' => 1,
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
