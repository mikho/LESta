<?php

use App\Actions\CronJobs\UpdateCronJob;
use App\Enums\ProvisioningStatus;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\CronJob;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('an owner can update a cron job, bumping the desired state version', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);
    $cronJob = CronJob::factory()->for($node)->create(['minute' => '*', 'hour' => '*', 'command' => 'old command']);
    $owner = Membership::factory()->for($cronJob->account)->owner()->create()->user;

    $updated = app(UpdateCronJob::class)->handle($owner, $cronJob, [
        'minute' => '30',
        'hour' => '4',
        'command' => 'new command',
    ]);

    expect($updated->minute)->toBe('30')
        ->and($updated->hour)->toBe('4')
        ->and($updated->command)->toBe('new command')
        ->and($updated->desired_state_version)->toBe(2)
        ->and(AuditEvent::where('action', 'cron_job.updated')->where('auditable_id', $cronJob->id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_id', $cronJob->id)
        ->where('operation', ProvisioningVerb::Update)
        ->first();

    expect($operation)->not->toBeNull()
        ->and($operation->status)->toBe(ProvisioningStatus::Applied)
        ->and($operation->desired_state_version)->toBe(2);
});

test('updating a cron job with a partial payload leaves the other fields untouched', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);
    $cronJob = CronJob::factory()->for($node)->create(['minute' => '15', 'hour' => '2', 'command' => 'keep me']);
    $owner = Membership::factory()->for($cronJob->account)->owner()->create()->user;

    $updated = app(UpdateCronJob::class)->handle($owner, $cronJob, ['minute' => '45']);

    expect($updated->minute)->toBe('45')
        ->and($updated->hour)->toBe('2')
        ->and($updated->command)->toBe('keep me');
});

test('a non-owner member cannot update a cron job', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);
    $cronJob = CronJob::factory()->for($node)->create();
    $member = Membership::factory()->for($cronJob->account)->member()->create()->user;

    app(UpdateCronJob::class)->handle($member, $cronJob, ['command' => 'new command']);
})->throws(AuthorizationException::class);
