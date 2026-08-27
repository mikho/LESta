<?php

use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\Account;
use App\Models\AuditEvent;
use App\Models\ProvisioningOperation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

test('a provisioning operation and an audit event written in the same rolled-back transaction leave no rows', function () {
    $account = Account::factory()->create();

    try {
        DB::transaction(function () use ($account) {
            ProvisioningOperation::create([
                'provisionable_type' => $account->getMorphClass(),
                'provisionable_id' => $account->id,
                'resource_id' => $account->uuid,
                'capability' => 'web.nginx.v1',
                'operation' => ProvisioningVerb::Create,
                'status' => ProvisioningStatus::Pending,
                'desired_state_version' => 1,
                'payload' => [],
                'correlation_id' => (string) Str::uuid(),
                'idempotency_key' => (string) Str::uuid(),
                'issued_at' => now(),
                'request_digest' => 'sha256:'.hash('sha256', 'test'),
            ]);

            AuditEvent::create([
                'actor_type' => null,
                'actor_id' => null,
                'auditable_type' => $account->getMorphClass(),
                'auditable_id' => $account->id,
                'action' => 'account.suspended',
                'correlation_id' => (string) Str::uuid(),
            ]);

            throw new RuntimeException('force rollback');
        });
    } catch (RuntimeException) {
        // expected
    }

    expect(ProvisioningOperation::count())->toBe(0)
        ->and(AuditEvent::count())->toBe(0);
});
