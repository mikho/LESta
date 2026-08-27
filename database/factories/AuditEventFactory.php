<?php

namespace Database\Factories;

use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<AuditEvent>
 */
class AuditEventFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $actor = User::factory()->create();
        $auditable = Account::factory()->create();

        return [
            'actor_type' => $actor->getMorphClass(),
            'actor_id' => $actor->id,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => $auditable->id,
            'action' => 'account.suspended',
            'correlation_id' => (string) Str::uuid(),
            'metadata' => null,
            'ip_address' => null,
            'user_agent' => null,
        ];
    }
}
