<?php

use App\Models\CronJob;
use App\Models\CronJobExecution;
use App\Models\Node;

function cronExecutionsPayload(array $executions): array
{
    return ['executions' => $executions];
}

function executionEntry(CronJob $cronJob, array $overrides = []): array
{
    return array_merge([
        'resource_id' => $cronJob->uuid,
        'started_at' => now()->subMinute()->toIso8601String(),
        'finished_at' => now()->toIso8601String(),
        'exit_code' => 0,
        'output' => 'hello world',
    ], $overrides);
}

test('a batch of cron executions is accepted and creates rows', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $cronJob = CronJob::factory()->for($node)->create();

    $entry = executionEntry($cronJob);

    $response = $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/cron-executions', cronExecutionsPayload([$entry]));

    $response->assertOk()->assertJson(['accepted' => 1]);

    expect(CronJobExecution::query()->where('cron_job_id', $cronJob->id)->count())->toBe(1);

    $execution = CronJobExecution::query()->where('cron_job_id', $cronJob->id)->first();
    expect($execution->exit_code)->toBe(0)
        ->and($execution->output)->toBe('hello world');
});

test('resending the identical batch creates no duplicates', function () {
    $node = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $cronJob = CronJob::factory()->for($node)->create();

    $entry = executionEntry($cronJob);

    $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/cron-executions', cronExecutionsPayload([$entry]))
        ->assertOk();

    $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/cron-executions', cronExecutionsPayload([$entry]))
        ->assertOk();

    expect(CronJobExecution::query()->where('cron_job_id', $cronJob->id)->count())->toBe(1);
});

test('an execution claiming a resource_id belonging to a different node is silently skipped', function () {
    $node = Node::factory()->create();
    $otherNode = Node::factory()->create();
    $credential = $node->completeEnrollment('1', '1.0.0');
    $cronJobOnOtherNode = CronJob::factory()->for($otherNode)->create();

    $entry = executionEntry($cronJobOnOtherNode);

    $response = $this->withHeader('Authorization', 'Bearer '.$credential)
        ->postJson('/agent/v1/cron-executions', cronExecutionsPayload([$entry]));

    $response->assertOk()->assertJson(['accepted' => 0]);

    expect(CronJobExecution::query()->count())->toBe(0);
});

test('an unauthenticated cron-executions request is rejected', function () {
    $response = $this->postJson('/agent/v1/cron-executions', cronExecutionsPayload([]));

    $response->assertStatus(401);
});
