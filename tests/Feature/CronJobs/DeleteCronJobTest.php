<?php

use App\Actions\CronJobs\DeleteCronJob;
use App\Enums\ProvisioningVerb;
use App\Models\AuditEvent;
use App\Models\CronJob;
use App\Models\Membership;
use App\Models\Node;
use App\Models\NodeCapability;
use App\Models\ProvisioningOperation;
use Illuminate\Auth\Access\AuthorizationException;

test('deleting a suspended cron job force-unsuspends then deletes as one action', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);
    $cronJob = CronJob::factory()->suspended()->for($node)->create();
    $owner = Membership::factory()->for($cronJob->account)->owner()->create()->user;
    $id = $cronJob->id;

    app(DeleteCronJob::class)->handle($owner, $cronJob);

    expect(CronJob::find($id))->toBeNull()
        ->and(AuditEvent::where('action', 'cron_job.deleted')->where('auditable_id', $id)->exists())->toBeTrue();

    $operation = ProvisioningOperation::where('provisionable_id', $id)
        ->where('operation', ProvisioningVerb::Delete)
        ->first();

    expect($operation)->not->toBeNull();
});

test('a non-owner member cannot delete a cron job', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);
    $cronJob = CronJob::factory()->for($node)->create();
    $member = Membership::factory()->for($cronJob->account)->member()->create()->user;

    app(DeleteCronJob::class)->handle($member, $cronJob);
})->throws(AuthorizationException::class);

test('the delete operation payload reflects the job state before deletion', function () {
    $node = Node::factory()->create();
    NodeCapability::factory()->for($node)->create(['capability' => 'scheduler.account-cron.v1']);
    $cronJob = CronJob::factory()->for($node)->create(['command' => 'echo gone']);
    $owner = Membership::factory()->for($cronJob->account)->owner()->create()->user;
    $id = $cronJob->id;

    app(DeleteCronJob::class)->handle($owner, $cronJob);

    $operation = ProvisioningOperation::where('provisionable_id', $id)
        ->where('operation', ProvisioningVerb::Delete)
        ->first();

    expect($operation->payload['command'])->toBe('echo gone');
});
