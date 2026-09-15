<?php

use App\Actions\Provisioning\RecordsProvisioningOperation;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\WebDomain;
use Illuminate\Support\Str;

test('recording a provisioning operation produces a pending row with correct resource_id and request_digest shape', function () {
    $webDomain = WebDomain::factory()->create();

    $operation = app(RecordsProvisioningOperation::class)->record(
        $webDomain,
        'web.nginx.v1',
        ProvisioningVerb::Create,
        ['domain' => 'example.test'],
        (string) Str::uuid(),
    );

    expect($operation->status)->toBe(ProvisioningStatus::Pending)
        ->and($operation->resource_id)->toBe($webDomain->uuid)
        ->and($operation->request_digest)->toMatch('/^sha256:[a-f0-9]{64}$/')
        ->and($operation->idempotency_key)->not->toBeNull()
        ->and($operation->desired_state_version)->toBe(1);
});
