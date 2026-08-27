<?php

use App\Jobs\DispatchProvisioningOperation;
use App\Models\ProvisioningOperation;
use Illuminate\Support\Facades\Queue;

test('only stale pending operations are re-dispatched by the backstop command', function () {
    Queue::fake();

    $stale = ProvisioningOperation::factory()->pending()->create(['issued_at' => now()->subMinutes(10)]);
    $fresh = ProvisioningOperation::factory()->pending()->create(['issued_at' => now()]);
    $applied = ProvisioningOperation::factory()->applied()->create(['issued_at' => now()->subMinutes(10)]);

    $this->artisan('provisioning:dispatch-pending')->assertExitCode(0);

    Queue::assertPushed(DispatchProvisioningOperation::class, function ($job) use ($stale) {
        return $job->provisioningOperationId === $stale->id;
    });
    Queue::assertNotPushed(DispatchProvisioningOperation::class, function ($job) use ($fresh) {
        return $job->provisioningOperationId === $fresh->id;
    });
    Queue::assertNotPushed(DispatchProvisioningOperation::class, function ($job) use ($applied) {
        return $job->provisioningOperationId === $applied->id;
    });
});
