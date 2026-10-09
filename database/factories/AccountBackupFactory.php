<?php

namespace Database\Factories;

use App\Enums\ProvisioningStatus;
use App\Models\Account;
use App\Models\AccountBackup;
use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccountBackup>
 */
class AccountBackupFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'account_id' => Account::factory(),
            'node_id' => Node::factory(),
            'label' => null,
            'kind' => 'manual',
            'encryption_key' => bin2hex(random_bytes(32)),
            'requested_parts' => ['files', 'databases', 'mail'],
            'desired_state_version' => 1,
        ];
    }

    /**
     * A finished backup with node-side metadata.
     */
    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => ProvisioningStatus::Applied,
            'parts' => ['files', 'databases'],
            'size_bytes' => 1234567,
            'checksum' => 'sha256:'.str_repeat('a', 64),
            'artifact_path' => '/var/lib/lesta/backups/accounts/lesta-t1/'.fake()->uuid().'.acct.enc',
            'completed_at' => now(),
        ]);
    }
}
