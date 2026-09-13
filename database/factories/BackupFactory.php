<?php

namespace Database\Factories;

use App\Enums\ProvisioningStatus;
use App\Models\Backup;
use App\Models\Node;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Backup>
 */
class BackupFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'node_id' => Node::factory(),
            'label' => null,
            'encryption_key' => bin2hex(random_bytes(32)),
            'status' => ProvisioningStatus::Pending,
            'included_capabilities' => null,
            'size_bytes' => null,
            'checksum' => null,
            'artifact_path' => null,
            'error_message' => null,
            'desired_state_version' => 1,
            'completed_at' => null,
        ];
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => ProvisioningStatus::Applied,
            'included_capabilities' => ['web.nginx.v1', 'dns.bind9.v1'],
            'size_bytes' => 1024,
            'checksum' => 'sha256:'.hash('sha256', 'test'),
            'artifact_path' => '/var/lib/lesta/backups/'.fake()->uuid().'.tar.enc',
            'completed_at' => now(),
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => ProvisioningStatus::Failed,
            'error_message' => 'a real failure reason',
            'completed_at' => now(),
        ]);
    }
}
