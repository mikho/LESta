<?php

use App\Enums\ProvisioningStatus;
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

test('a dispatched operation past its deadline is failed by the backstop command', function () {
    $overdue = ProvisioningOperation::factory()->dispatched()->create(['deadline' => now()->subMinute()]);

    $this->artisan('provisioning:dispatch-pending')->assertExitCode(0);

    $overdue->refresh();

    expect($overdue->status)->toBe(ProvisioningStatus::Failed)
        ->and($overdue->errors)->toBe([['code' => 'deadline_exceeded', 'message' => 'Operation exceeded its dispatch deadline without a result from the node.']])
        ->and($overdue->completed_at)->not->toBeNull();
});

test('a dispatched operation not yet past its deadline is left untouched by the backstop command', function () {
    $notYetOverdue = ProvisioningOperation::factory()->dispatched()->create(['deadline' => now()->addMinutes(5)]);

    $this->artisan('provisioning:dispatch-pending')->assertExitCode(0);

    expect($notYetOverdue->refresh()->status)->toBe(ProvisioningStatus::Dispatched);
});

test('the backstop command still re-dispatches stale pending operations alongside deadline reconciliation', function () {
    Queue::fake();

    $stale = ProvisioningOperation::factory()->pending()->create(['issued_at' => now()->subMinutes(10)]);
    $overdue = ProvisioningOperation::factory()->dispatched()->create(['deadline' => now()->subMinute()]);

    $this->artisan('provisioning:dispatch-pending')->assertExitCode(0);

    Queue::assertPushed(DispatchProvisioningOperation::class, function ($job) use ($stale) {
        return $job->provisioningOperationId === $stale->id;
    });

    expect($overdue->refresh()->status)->toBe(ProvisioningStatus::Failed);
});
