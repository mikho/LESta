<?php

namespace Database\Factories;

use App\Models\AccountBackup;
use App\Models\AccountBackupDownload;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountBackupDownload>
 */
class AccountBackupDownloadFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_backup_id' => AccountBackup::factory()->completed(),
            'token_hash' => hash('sha256', fake()->unique()->sha256()),
            'status' => 'pending',
            'size_bytes' => 0,
            'expires_at' => now()->addHours(2),
        ];
    }
}
