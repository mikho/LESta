<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountBackupDestination;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountBackupDestination>
 */
class AccountBackupDestinationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'enabled' => true,
            'endpoint' => 'https://s3.eu-west-1.amazonaws.com',
            'region' => 'eu-west-1',
            'bucket' => 'my-backups',
            'prefix' => 'lesta/',
            'access_key' => 'AKIAEXAMPLEKEY',
            'secret_key' => 'example-secret-key/1234',
        ];
    }
}
