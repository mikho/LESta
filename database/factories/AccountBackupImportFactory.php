<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AccountBackupImport;
use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountBackupImport>
 */
class AccountBackupImportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'node_id' => Node::factory(),
            'status' => 'uploading',
            'size_bytes' => 0,
            'expires_at' => now()->addHours(AccountBackupImport::KEEP_HOURS),
        ];
    }
}
