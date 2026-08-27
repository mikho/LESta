<?php

use App\Contracts\Provisioner;
use App\Enums\ProvisioningStatus;
use App\Jobs\DispatchProvisioningOperation;
use App\Models\ProvisioningOperation;

test('calling the dispatch job handle twice directly is a no-op on the second call', function () {
    $operation = ProvisioningOperation::factory()->pending()->create();
    $job = new DispatchProvisioningOperation($operation->id);

    $job->handle(app(Provisioner::class));

    $operation->refresh();
    expect($operation->status)->toBe(ProvisioningStatus::Applied);
    $firstGenerationId = $operation->generation_id;
    $firstAttempts = $operation->attempts;

    // Simulate a redelivered queue message calling handle() again directly.
    $job->handle(app(Provisioner::class));

    $operation->refresh();
    expect($operation->generation_id)->toBe($firstGenerationId)
        ->and($operation->attempts)->toBe($firstAttempts)
        ->and($operation->status)->toBe(ProvisioningStatus::Applied);
});
