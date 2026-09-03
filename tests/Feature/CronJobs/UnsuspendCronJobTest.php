<?php

use App\Actions\CronJobs\UnsuspendCronJob;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\CronJob;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can unsuspend their cron job', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);
    $cronJob = CronJob::factory()->suspended()->for($node)->create();
    $owner = Membership::factory()->for($cronJob->account)->owner()->create()->user;

    app(UnsuspendCronJob::class)->handle($owner, $cronJob);

    $cronJob->refresh();

    expect($cronJob->isSuspended())->toBeFalse()
        ->and($cronJob->suspension_source)->toBeNull()
        ->and(AuditEvent::where('action', 'cron_job.unsuspended')->where('auditable_id', $cronJob->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_id', $cronJob->id)
        ->where('operation', ProvisioningVerb::Unsuspend)
        ->first();

    expect($operation)->not->toBeNull();
});

test('unsuspending an already-active cron job is a no-op', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);
    $cronJob = CronJob::factory()->for($node)->create();
    $owner = Membership::factory()->for($cronJob->account)->owner()->create()->user;

    app(UnsuspendCronJob::class)->handle($owner, $cronJob);

    expect(AuditEvent::where('action', 'cron_job.unsuspended')->where('auditable_id', $cronJob->id)->count())->toBe(0);
});

test('a non-owner member cannot unsuspend a cron job', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);
    $cronJob = CronJob::factory()->suspended()->for($node)->create();
    $member = Membership::factory()->for($cronJob->account)->member()->create()->user;

    app(UnsuspendCronJob::class)->handle($member, $cronJob);
})->throws(AuthorizationException::class);
