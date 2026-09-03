<?php

use App\Actions\CronJobs\SuspendCronJob;
use App\Enums\ProvisioningVerb;
use App\Enums\SuspensionSource;
use App\Models\AuditEvent;
use App\Models\CronJob;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can suspend their cron job', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);
    $cronJob = CronJob::factory()->for($node)->create();
    $owner = Membership::factory()->for($cronJob->account)->owner()->create()->user;

    app(SuspendCronJob::class)->handle($owner, $cronJob);

    $cronJob->refresh();

    expect($cronJob->isSuspended())->toBeTrue()
        ->and($cronJob->suspension_source)->toBe(SuspensionSource::Manual)
        ->and($cronJob->desired_state_version)->toBe(2)
        ->and(AuditEvent::where('action', 'cron_job.suspended')->where('auditable_id', $cronJob->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_id', $cronJob->id)
        ->where('operation', ProvisioningVerb::Suspend)
        ->first();

    expect($operation)->not->toBeNull();
});

test('duplicate suspend submissions do not create a second audit row', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);
    $cronJob = CronJob::factory()->for($node)->create();
    $owner = Membership::factory()->for($cronJob->account)->owner()->create()->user;

    app(SuspendCronJob::class)->handle($owner, $cronJob);
    app(SuspendCronJob::class)->handle($owner, $cronJob);

    expect(AuditEvent::where('action', 'cron_job.suspended')->where('auditable_id', $cronJob->id)->count())->toBe(1);
});

test('a non-owner member cannot suspend a cron job', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);
    $cronJob = CronJob::factory()->for($node)->create();
    $member = Membership::factory()->for($cronJob->account)->member()->create()->user;

    app(SuspendCronJob::class)->handle($member, $cronJob);
})->throws(AuthorizationException::class);
